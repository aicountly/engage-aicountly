<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Telling a customer something about their AI credits, through Console's hub.
 *
 * Console owns the wording (registered templates), the channel adapters, the rate
 * limits, the idempotency and the send log. This class owns only: who to tell, which
 * trigger, and what the variables are.
 *
 * WHY THE HUB AND NOT ENGAGE'S OWN MAILER. Engage already sends seller e-mail
 * directly, using provider credentials federated from Console — and that pattern
 * costs the product its own templates, its own failover and its own honesty
 * semantics. For messages about somebody's money, Console's registered-template path
 * is worth the extra hop: a customer-facing body that is reviewed once and cannot be
 * overridden by a caller, per-recipient rate limits, idempotency keys, and one send
 * log for every channel.
 *
 * CHANNELS THAT ACTUALLY WORK TODAY. E-mail. SMS and WhatsApp templates exist and
 * are seeded, but they are seeded inactive — an Indian SMS needs its exact body on
 * DLT first and a WhatsApp template needs Meta's approval, and Console's
 * SMS_SKIP_SEND / WHATSAPP_SKIP_SEND default to true besides. Console answers an
 * inactive template with 200 and `skipped_reason`, not an error, so asking for those
 * channels now is harmless and becomes live the day the approvals land. What is
 * genuinely missing is a phone number: the cross-product contact lookup returns
 * e-mail only, by deliberate directory policy, so `mobile` is almost always null and
 * those sends are skipped for want of a recipient rather than for want of approval.
 */
final class CustomerNotifier
{
    public const PRODUCT = 'engage';

    public function __construct(
        private ?BaseConnection $db = null,
        private readonly ?Client $http = null,
    ) {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function configured(): bool
    {
        return rtrim((string) env('CONSOLE_API_URL', (string) env('CONSOLE_API_BASE_URL', '')), '/') !== ''
            && (string) env('CONSOLE_API_TOKEN', '') !== '';
    }

    /**
     * What Engage knows about a holder: the address provisioning recorded.
     *
     * @return array{email:?string, name:?string, mobile:?string}
     */
    public function contact(string $holderUuid): array
    {
        $r = $this->db()->table('engage_holders')->select('email, name')->where('holder_uuid', $holderUuid)->get()->getRowArray();

        return [
            'email'  => ($r['email'] ?? '') !== '' ? (string) $r['email'] : null,
            'name'   => ($r['name'] ?? '') !== '' ? (string) $r['name'] : null,
            // No mobile anywhere in Engage for a holder, and my.aicountly's contact
            // lookup deliberately does not return one. Left here so the shape is honest
            // about what is missing rather than silently absent.
            'mobile' => null,
        ];
    }

    /**
     * Send one notification on one channel.
     *
     * Never throws. A notification that cannot be sent is reported, not escalated: it
     * must never turn a successful voucher redemption or payment into a failure.
     *
     * @param array<string,scalar|null> $variables
     * @return array{sent:bool, reason:?string}
     */
    public function send(string $triggerKey, string $channel, array $recipient, array $variables, string $idempotencyKey, string $product = self::PRODUCT): array
    {
        if (! self::configured()) {
            return ['sent' => false, 'reason' => 'console_not_configured'];
        }
        $address = $channel === 'email' ? ($recipient['email'] ?? null) : ($recipient['mobile'] ?? null);
        if ($address === null || $address === '') {
            // The commonest case for sms and whatsapp, and not an error: we simply do
            // not hold a number for this person.
            return ['sent' => false, 'reason' => 'no_recipient'];
        }

        $base = rtrim((string) env('CONSOLE_API_URL', (string) env('CONSOLE_API_BASE_URL', '')), '/');
        $body = [
            'product'     => $product,
            'trigger_key' => $triggerKey,
            'channel'     => $channel,
            'variables'   => $variables,
            'recipient'   => array_filter([
                'email'  => $recipient['email'] ?? null,
                'mobile' => $recipient['mobile'] ?? null,
                'name'   => $recipient['name'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'options'     => [
                // One key per (trigger, holder, occasion), so a retried run cannot send a
                // customer the same message twice — Console answers duplicate:true.
                'idempotency_key' => mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '-', $idempotencyKey) ?? '', 0, 64),
            ],
        ];

        try {
            $res = ($this->http ?? new Client(['timeout' => 12]))->request('POST', $base . '/portal/notifications/send', [
                'headers'     => ['Authorization' => 'Bearer ' . (string) env('CONSOLE_API_TOKEN', ''), 'Content-Type' => 'application/json'],
                'json'        => $body,
                'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            $json = json_decode((string) $res->getBody(), true);
            $data = is_array($json) ? ($json['data'] ?? $json) : [];

            if ($status >= 200 && $status < 300) {
                if (($data['sent'] ?? false) === true || ($data['duplicate'] ?? false) === true) {
                    return ['sent' => true, 'reason' => null];
                }

                // A template still awaiting DLT or Meta approval lands here. Expected, not
                // a fault, and the reason says which.
                return ['sent' => false, 'reason' => (string) ($data['skipped_reason'] ?? 'not_sent')];
            }
            $reason = is_array($json) ? (string) ($json['code'] ?? $json['message'] ?? "http_{$status}") : "http_{$status}";
            log_message('warning', 'customer notification {t}/{c} refused: {r}', ['t' => $triggerKey, 'c' => $channel, 'r' => $reason]);

            return ['sent' => false, 'reason' => $reason];
        } catch (GuzzleException $e) {
            log_message('warning', 'customer notification {t}/{c} not sent: {m}', ['t' => $triggerKey, 'c' => $channel, 'm' => $e->getMessage()]);

            return ['sent' => false, 'reason' => 'console_unreachable'];
        }
    }

    /**
     * Try the channels a trigger is meant to use, best first, and stop at the first
     * that actually goes.
     *
     * Deliberately NOT "send on all of them": a customer who gets the same message by
     * e-mail, SMS and WhatsApp learns to ignore all three. The escalation exists so
     * that a missing phone number falls back to e-mail, not so that everybody gets
     * three copies.
     *
     * @param list<string> $channels
     * @param array<string,scalar|null> $variables
     * @return array{sent:bool, channel:?string, reason:?string}
     */
    public function sendFirstAvailable(string $triggerKey, array $channels, array $recipient, array $variables, string $idempotencyKey, string $product = self::PRODUCT): array
    {
        $last = 'no_channel';
        foreach ($channels as $channel) {
            $r = $this->send($triggerKey, $channel, $recipient, $variables, $idempotencyKey . ':' . $channel, $product);
            if ($r['sent']) {
                return ['sent' => true, 'channel' => $channel, 'reason' => null];
            }
            $last = $r['reason'] ?? 'not_sent';
        }

        return ['sent' => false, 'channel' => null, 'reason' => $last];
    }
}

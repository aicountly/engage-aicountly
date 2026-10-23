<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Email\EmailDispatcher;

/**
 * Email to sellers (activation link, password reset link, login code).
 *
 * ENGAGE_SELLER_MAILER selects the channel:
 *   console_providers (default; also "console" and unset) — Console's email providers for app_code engage:
 *             GET {CONSOLE_API_URL | CONSOLE_API_BASE_URL}/portal/email/providers?app_code=engage,
 *             Authorization: Bearer {CONSOLE_API_TOKEN} (capability email.credentials), cached and encrypted
 *             (Libraries/Email/EmailProviderResolver). A send tries the primary, then each fallback; a resend
 *             (staff "resend activation") tries the fallbacks first (Libraries/Email/EmailDispatcher).
 *             When Console gives no provider (not configured, refused, unreachable with no usable cache, or an
 *             empty list), Engage's own SMTP below is used if it is configured. When Console's providers were
 *             tried and none accepted, nothing else is tried: the answer is not sent, with each provider's reason.
 *   smtp    — only CodeIgniter Email over ENGAGE_SMTP_HOST / _PORT / _USER / _PASS / _CRYPTO, from ENGAGE_MAIL_FROM.
 *   console_notify — DEPRECATED, explicit opt-in only: POST {CONSOLE_API_URL}/portal/notifications/send with
 *             notify.send + notify.direct. Engage's Console caller holds neither and Console delivers notify to
 *             staff only, so this mode does not reach sellers today. Kept for a future Console change; do not use.
 *   off     — no mailer: nothing is sent. Callers show the link once to the staff member instead.
 *
 * From-name: ENGAGE_MAIL_FROM_NAME, default "Aicountly Sellers". From-address: the provider's from_email, else
 * ENGAGE_MAIL_FROM.
 *
 * Production honesty: send() reports sent=true only when a provider (or the SMTP server, or Console notify)
 * accepted the message. One send() call sends at most one copy: providers are tried in turn only until one
 * accepts. Callers issue a new token (and idempotency key) per request, so a retry is a new message by design.
 * No secret is ever returned or logged.
 */
class SellerMailer
{
    public const TRIGGERS = ['seller_activation', 'seller_password_reset', 'seller_login_otp'];
    public const DEFAULT_FROM_NAME = 'Aicountly Sellers';

    /** @var callable|null (url, headers, body) → {status, body} — Console notify (deprecated mode) */
    private $transport;

    private ?EmailDispatcher $dispatcher;

    /** @var callable|null (email, name, subject, text) → bool — test seam for the SMTP channel */
    private $smtp;

    public function __construct(?callable $transport = null, ?EmailDispatcher $dispatcher = null, ?callable $smtp = null)
    {
        $this->transport  = $transport;
        $this->dispatcher = $dispatcher;
        $this->smtp       = $smtp;
    }

    private static function mode(): string
    {
        $mode = strtolower(trim((string) env('ENGAGE_SELLER_MAILER', '')));

        return match ($mode) {
            '', 'console', 'console_providers' => 'console_providers',
            'smtp', 'console_notify', 'off', 'none' => $mode === 'none' ? 'off' : $mode,
            default => 'unknown',
        };
    }

    private function consoleConfigured(): bool
    {
        return $this->consoleBase() !== '' && trim((string) env('CONSOLE_API_TOKEN', '')) !== '';
    }

    private static function smtpConfigured(): bool
    {
        return trim((string) env('ENGAGE_SMTP_HOST', '')) !== '' && trim((string) env('ENGAGE_MAIL_FROM', '')) !== '';
    }

    /** The configured channel (cheap: no Console lookup). */
    public function channel(): ?string
    {
        return match (self::mode()) {
            'console_providers' => $this->consoleConfigured() ? 'console_providers' : (self::smtpConfigured() ? 'smtp' : null),
            'smtp'              => self::smtpConfigured() ? 'smtp' : null,
            'console_notify'    => $this->consoleConfigured() ? 'console_notify' : null,
            default             => null,
        };
    }

    public function isConfigured(): bool
    {
        return $this->channel() !== null;
    }

    /** Human-readable status for /health and the staff UI (provider codes only, never secrets). */
    public function status(): string
    {
        $mode = self::mode();
        $channel = $this->channel();
        if ($channel === 'console_providers') {
            $line = $this->dispatcher()->resolver()->summary()['line'];

            return 'ok (Console providers: ' . $line . (self::smtpConfigured() ? '; SMTP fallback when Console has none' : '') . ')';
        }
        if ($channel !== null) {
            return 'ok (' . $channel . ($channel === 'console_notify' ? ' — deprecated, does not reach sellers' : '') . ')';
        }

        return match ($mode) {
            'off'               => 'ENGAGE_SELLER_MAILER=off — seller emails are not sent; activation / reset links are shown once to staff',
            'console_providers' => 'Console (CONSOLE_API_URL / CONSOLE_API_TOKEN) and ENGAGE_SMTP_HOST / ENGAGE_MAIL_FROM are not set — seller emails are not sent; links are shown once to staff',
            'unknown'           => 'ENGAGE_SELLER_MAILER has an unknown value — seller emails are not sent',
            default             => "ENGAGE_SELLER_MAILER={$mode} but its settings are incomplete — seller emails are not sent",
        };
    }

    /**
     * @param array<string,scalar|null> $vars
     * @param array{resend?:bool} $opts resend = a person asked for the mail again (fallback provider first)
     * @return array{sent:bool, channel:?string, reason:?string, provider?:?string}
     */
    public function send(string $trigger, string $email, string $name, array $vars, string $idempotencyKey, array $opts = []): array
    {
        $channel = $this->channel();
        if ($channel === null) {
            return ['sent' => false, 'channel' => null, 'reason' => 'not_configured'];
        }
        try {
            return match ($channel) {
                'console_providers' => $this->viaProviders($trigger, $email, $name, $vars, ! empty($opts['resend'])),
                'console_notify'    => $this->viaConsole($trigger, $email, $name, $vars, $idempotencyKey),
                default             => $this->viaSmtp($trigger, $email, $name, $vars),
            };
        } catch (\Throwable $e) {
            log_message('error', "Seller mail {$trigger} failed: " . $e->getMessage());

            return ['sent' => false, 'channel' => $channel, 'reason' => 'error'];
        }
    }

    private function dispatcher(): EmailDispatcher
    {
        return $this->dispatcher ??= EmailDispatcher::instance();
    }

    private static function fromName(): string
    {
        return trim((string) env('ENGAGE_MAIL_FROM_NAME', '')) ?: self::DEFAULT_FROM_NAME;
    }

    /** @return array{sent:bool, channel:string, reason:?string, provider:?string} */
    private function viaProviders(string $trigger, string $email, string $name, array $vars, bool $resend): array
    {
        $resolved = $this->dispatcher()->resolver()->resolve();
        if ($resolved['providers'] === []) {
            if (self::smtpConfigured()) {
                log_message('warning', "Seller mail {$trigger}: Console gave no email provider (" . ($resolved['note'] ?? 'none') . '); using ENGAGE_SMTP_*');

                return $this->viaSmtp($trigger, $email, $name, $vars) + ['provider' => null];
            }
            log_message('warning', "Seller mail {$trigger} not sent: Console gave no email provider (" . ($resolved['note'] ?? 'none') . ') and ENGAGE_SMTP_* is not set');

            return ['sent' => false, 'channel' => 'console_providers', 'reason' => 'no_provider', 'provider' => null];
        }
        [$subject, $text] = self::compose($trigger, $vars);
        $html = '<p>' . str_replace("\n", "<br>\n", htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
        $r = $this->dispatcher()->send([['email' => $email, 'name' => $name]], $subject, $html, $text, [
            'resend'    => $resend,
            'from_name' => self::fromName(),
        ]);
        if ($r['ok']) {
            return ['sent' => true, 'channel' => 'console_providers', 'reason' => null, 'provider' => $r['provider']];
        }
        log_message('warning', "Seller mail {$trigger} not accepted by any Console provider: " . (string) $r['error']);

        return ['sent' => false, 'channel' => 'console_providers', 'reason' => 'providers_rejected', 'provider' => null];
    }

    /**
     * DEPRECATED (ENGAGE_SELLER_MAILER=console_notify only): Console notify cannot reach sellers today.
     *
     * @return array{sent:bool, channel:string, reason:?string}
     */
    private function viaConsole(string $trigger, string $email, string $name, array $vars, string $idem): array
    {
        $body = json_encode([
            'product'     => (string) env('ENGAGE_SELLER_NOTIFY_PRODUCT', 'engage') ?: 'engage',
            'channel'     => 'email',
            'trigger_key' => $trigger,
            'recipient'   => ['email' => $email, 'name' => $name],
            'variables'   => $vars,
            'options'     => ['idempotency_key' => substr(preg_replace('/[^A-Za-z0-9_.:\-]/', '', $idem) ?: bin2hex(random_bytes(8)), 0, 64)],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . trim((string) env('CONSOLE_API_TOKEN', '')),
            'X-Source: engage.aicountly.org',
        ];
        $url = $this->consoleBase() . '/portal/notifications/send';
        $res = $this->transport !== null ? ($this->transport)($url, $headers, (string) $body) : self::curlPost($url, $headers, (string) $body);
        $json = is_string($res['body'] ?? null) ? json_decode((string) $res['body'], true) : null;
        $sent = (int) ($res['status'] ?? 0) === 200 && is_array($json) && ($json['data']['sent'] ?? false) === true;
        if ($sent) {
            return ['sent' => true, 'channel' => 'console_notify', 'reason' => null];
        }
        $reason = is_array($json) ? (string) ($json['data']['skipped_reason'] ?? $json['error']['code'] ?? $json['code'] ?? ('http_' . (int) ($res['status'] ?? 0)))
            : 'http_' . (int) ($res['status'] ?? 0);
        log_message('warning', "Seller mail {$trigger} not sent by Console: {$reason}");

        return ['sent' => false, 'channel' => 'console_notify', 'reason' => $reason !== '' ? $reason : 'not_sent'];
    }

    /** @return array{sent:bool, channel:string, reason:?string} */
    private function viaSmtp(string $trigger, string $email, string $name, array $vars): array
    {
        [$subject, $text] = self::compose($trigger, $vars);
        if ($this->smtp !== null) {
            $ok = (bool) ($this->smtp)($email, $name, $subject, $text);

            return ['sent' => $ok, 'channel' => 'smtp', 'reason' => $ok ? null : 'smtp_rejected'];
        }
        $mailer = \Config\Services::email([
            'protocol'   => 'smtp',
            'SMTPHost'   => (string) env('ENGAGE_SMTP_HOST', ''),
            'SMTPPort'   => (int) env('ENGAGE_SMTP_PORT', 587),
            'SMTPUser'   => (string) env('ENGAGE_SMTP_USER', ''),
            'SMTPPass'   => (string) env('ENGAGE_SMTP_PASS', ''),
            'SMTPCrypto' => (string) env('ENGAGE_SMTP_CRYPTO', 'tls'),
            'mailType'   => 'text',
        ], false);
        $mailer->setFrom((string) env('ENGAGE_MAIL_FROM', ''), self::fromName());
        $mailer->setTo($email);
        $mailer->setSubject($subject);
        $mailer->setMessage($text);
        $ok = $mailer->send(false);
        if (! $ok) {
            log_message('error', "Seller mail {$trigger} rejected by SMTP: " . substr(strip_tags((string) $mailer->printDebugger(['headers'])), 0, 300));
        }

        return ['sent' => (bool) $ok, 'channel' => 'smtp', 'reason' => $ok ? null : 'smtp_rejected'];
    }

    /** @return array{0:string, 1:string} subject, text body (providers and SMTP; Console notify uses its own templates). */
    public static function compose(string $trigger, array $vars): array
    {
        $n = (string) ($vars['name'] ?? 'there');
        switch ($trigger) {
            case 'seller_activation':
                return ['Activate your Aicountly seller account',
                    "Hello {$n},\n\nYour application to become an Aicountly seller was approved. Your seller code is {$vars['seller_code']}.\n\n"
                    . "Set your password here (the link works once and expires {$vars['expires_at']}):\n{$vars['activation_url']}\n\n"
                    . "If you did not apply, ignore this email.\n\nAicountly"];
            case 'seller_password_reset':
                return ['Reset your Aicountly seller password',
                    "Hello {$n},\n\nUse this link to set a new password (it works once and expires {$vars['expires_at']}):\n{$vars['reset_url']}\n\n"
                    . "If you did not ask for this, ignore this email — your password is unchanged.\n\nAicountly"];
            case 'seller_login_otp':
                return ['Your Aicountly seller sign-in code',
                    "Hello {$n},\n\nYour sign-in code is {$vars['code']}. It expires in {$vars['expires_minutes']} minutes.\n\n"
                    . "If you did not try to sign in, change your password.\n\nAicountly"];
        }

        return ['Aicountly', ''];
    }

    private function consoleBase(): string
    {
        $base = trim((string) env('CONSOLE_API_URL', '')) ?: trim((string) env('CONSOLE_API_BASE_URL', ''));

        return rtrim($base, '/');
    }

    /** @return array{status:int, body:string|false} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $raw];
    }
}

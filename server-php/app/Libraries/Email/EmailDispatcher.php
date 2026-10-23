<?php

declare(strict_types=1);

namespace App\Libraries\Email;

/**
 * Sends one email through the providers Console assigns to Engage, with failover.
 * Copied from reach-aicountly (app/Libraries/Email/EmailDispatcher.php); Engage's sender variables; keep in step.
 *
 *   EmailDispatcher::instance()->send($to, $subject, $html, $text, ['resend' => true]);
 *
 * Order (EMAIL-CONTRACT):
 *   normal send  primary, then each fallback, until one accepts;
 *   resend       (a person asked for the mail again) fallbacks first, then the primary — the
 *                primary is the likeliest reason the first copy never arrived.
 *
 * Honesty: ok=true only when a provider accepted the message; otherwise ok=false and `error`
 * lists every provider's reason. Provider secrets never appear in a result or a log line.
 *
 * Sender identity: the From address is the provider's config.from_email when it has one (the
 * provider is authorised for that address — SMTP mailbox, SPF/DKIM domain), else ENGAGE_MAIL_FROM;
 * the display name is opts from_name, else ENGAGE_MAIL_FROM_NAME, else the provider's from_name.
 *
 * Options: resend (bool), from_email, from_name, reply_to, headers (SMTP only, e.g. List-Unsubscribe).
 */
final class EmailDispatcher
{
    private static ?self $instance = null;

    private EmailProviderResolver $resolver;

    /** @var callable(array<string,mixed>):object */
    private $transportFactory;

    /** @param (callable(array<string,mixed> $provider):object)|null $transportFactory returns an object with send(array $provider, array $msg): array */
    public function __construct(?EmailProviderResolver $resolver = null, ?callable $transportFactory = null)
    {
        $this->resolver         = $resolver ?? new EmailProviderResolver();
        $this->transportFactory = $transportFactory ?? static fn (array $provider): object => $provider['type'] === 'smtp'
            ? new SmtpProviderTransport()
            : new HttpApiProviderTransport();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Test seam: replace (or with null, reset) the shared dispatcher. */
    public static function setInstance(?self $dispatcher): void
    {
        self::$instance = $dispatcher;
    }

    public function resolver(): EmailProviderResolver
    {
        return $this->resolver;
    }

    public function isConfigured(): bool
    {
        return $this->resolver->resolve()['providers'] !== [];
    }

    /**
     * @param list<array<string,mixed>> $providers already in priority order
     * @return list<array<string,mixed>>
     */
    public static function order(array $providers, bool $resend): array
    {
        $providers = array_values($providers);
        if (!$resend || count($providers) < 2) {
            return $providers;
        }

        return array_merge(array_slice($providers, 1), [$providers[0]]);
    }

    /**
     * @param string|list<string|array{email:string, name?:string}> $to
     * @param array<string,mixed> $opts
     * @return array{ok:bool, provider:?string, provider_type:?string, id:?string, mode:?string, url:?string, source:string, resend:bool,
     *               attempts:list<array{provider:string, type:string, ok:bool, error:?string}>, error:?string}
     */
    public function send(string|array $to, string $subject, string $html, ?string $text = null, array $opts = []): array
    {
        $resend = !empty($opts['resend']);
        $result = ['ok' => false, 'provider' => null, 'provider_type' => null, 'id' => null, 'mode' => null, 'url' => null, 'source' => 'none', 'resend' => $resend, 'attempts' => [], 'error' => null];

        $recipients = [];
        foreach (is_array($to) ? $to : explode(',', $to) as $addr) {
            $addr = trim(is_array($addr) ? (string) ($addr['email'] ?? '') : (string) $addr);
            if ($addr !== '') {
                $recipients[] = $addr;
            }
        }
        foreach ($recipients as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                $recipients = [];
                break;
            }
        }
        if ($recipients === []) {
            $result['error'] = 'Invalid recipient email.';

            return $result;
        }

        try {
            $resolved = $this->resolver->resolve();
        } catch (\Throwable $e) {
            $resolved = ['providers' => [], 'source' => 'none', 'note' => 'provider lookup failed: ' . $e->getMessage()];
        }
        $result['source'] = (string) $resolved['source'];
        $providers = self::order($resolved['providers'], $resend);
        if ($providers === []) {
            $result['error'] = 'No email provider is configured: ' . ($resolved['note'] ?? 'nothing resolved') . '.';
            self::log(sprintf('not sent to=%s — %s', implode(',', $recipients), $result['error']), 'error');

            return $result;
        }

        $secrets = array_values(array_filter(array_map(static fn (array $p): string => (string) ($p['secret'] ?? ''), $resolved['providers']), static fn (string $s): bool => strlen($s) >= 4));
        $text  ??= self::htmlToText($html);
        $errors  = [];

        foreach ($providers as $provider) {
            $msg = self::message($provider, $recipients, $subject, $html, $text, $opts);
            try {
                $transport = ($this->transportFactory)($provider);
                $answer    = $transport->send($provider, $msg);
            } catch (\Throwable $e) {
                $answer = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
            }
            $ok    = is_array($answer) && !empty($answer['ok']);
            $error = $ok ? null : self::redact((string) (is_array($answer) ? ($answer['error'] ?? 'not accepted') : 'invalid transport answer'), $secrets);

            $result['attempts'][] = ['provider' => $provider['code'], 'type' => $provider['type'], 'ok' => $ok, 'error' => $error];
            if ($ok) {
                $result['ok']            = true;
                $result['provider']      = $provider['code'];
                $result['provider_type'] = $provider['type'];
                $result['id']            = isset($answer['id']) && $answer['id'] !== '' ? (string) $answer['id'] : null;
                $result['mode']          = isset($answer['mode']) && $answer['mode'] !== '' ? (string) $answer['mode'] : null;
                $result['url']           = isset($answer['url']) && $answer['url'] !== '' ? (string) $answer['url'] : null;
                self::log(sprintf('sent via %s (%s%s) to=%s subject=%s', $provider['code'], $result['source'], $resend ? ', resend' : '', implode(',', $recipients), self::clip($subject, 120)));

                return $result;
            }
            $errors[] = $provider['code'] . ': ' . $error;
            self::log(sprintf('provider %s did not accept to=%s — %s', $provider['code'], implode(',', $recipients), $error), 'warning');
        }

        $result['error'] = 'No email provider accepted the message — ' . implode('; ', $errors);

        return $result;
    }

    /**
     * @param array<string,mixed> $provider
     * @param list<string> $recipients
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private static function message(array $provider, array $recipients, string $subject, string $html, string $text, array $opts): array
    {
        $cfg       = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $fromEmail = trim((string) ($cfg['from_email'] ?? ''))
            ?: trim((string) ($opts['from_email'] ?? ''))
            ?: self::env('ENGAGE_MAIL_FROM')
            ?: 'support@aicountly.com';
        $fromName  = trim((string) ($opts['from_name'] ?? ''))
            ?: self::env('ENGAGE_MAIL_FROM_NAME')
            ?: trim((string) ($cfg['from_name'] ?? ''))
            ?: 'Aicountly Engage';
        $replyTo   = trim((string) ($opts['reply_to'] ?? ''))
            ?: self::env('ENGAGE_MAIL_REPLY_TO')
            ?: trim((string) ($cfg['reply_to'] ?? ''))
            ?: $fromEmail;

        return [
            'to'          => implode(',', $recipients),
            'subject'     => $subject,
            'html'        => $html,
            'text'        => $text,
            'from_email'  => $fromEmail,
            'from_name'   => $fromName,
            'reply_to'    => $replyTo,
            'headers'     => is_array($opts['headers'] ?? null) ? $opts['headers'] : [],
        ];
    }

    /** @param list<string> $secrets */
    private static function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace([$secret, base64_encode($secret)], '[redacted]', $text);
        }

        return self::clip($text, 500);
    }

    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/(p|div|tr|h[1-6]|li)>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private static function clip(string $text, int $max): string
    {
        return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
    }

    private static function env(string $key): string
    {
        $v = function_exists('env') ? env($key, '') : ($_ENV[$key] ?? getenv($key));

        return is_string($v) ? trim($v) : '';
    }

    private static function log(string $line, string $level = 'info'): void
    {
        if (function_exists('log_message')) {
            log_message($level, '[EmailDispatcher] ' . $line);

            return;
        }
        error_log('[EmailDispatcher] ' . $line);
    }
}

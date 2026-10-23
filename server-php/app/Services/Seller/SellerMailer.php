<?php
declare(strict_types=1);

namespace App\Services\Seller;

/**
 * Email to sellers (activation link, password reset link, login code).
 *
 * ENGAGE_SELLER_MAILER selects the channel:
 *   console — Console notifications: POST {CONSOLE_API_URL}/portal/notifications/send,
 *             Authorization: Bearer {CONSOLE_API_TOKEN} (capabilities notify.send + notify.direct),
 *             {product: ENGAGE_SELLER_NOTIFY_PRODUCT (default "engage"), channel: "email", trigger_key,
 *              recipient: {email, name}, variables, options: {idempotency_key}}.
 *             Sellers are not Console users, so Console must accept direct recipients for this product
 *             and have an active template per trigger key (seller_activation, seller_password_reset,
 *             seller_login_otp) — otherwise Console answers sent:false and we say so.
 *   smtp    — CodeIgniter Email over ENGAGE_SMTP_HOST / _PORT / _USER / _PASS / _CRYPTO, from ENGAGE_MAIL_FROM.
 *   (unset) — no mailer: nothing is sent. Callers show the link once to the staff member instead.
 *
 * Production honesty: send() reports sent=true only when Console answered sent:true or the SMTP server
 * accepted the message. Never anything else.
 */
class SellerMailer
{
    public const TRIGGERS = ['seller_activation', 'seller_password_reset', 'seller_login_otp'];

    /** @var callable|null (url, headers, body) → {status, body} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function channel(): ?string
    {
        $mode = strtolower(trim((string) env('ENGAGE_SELLER_MAILER', '')));
        if ($mode === 'console' && $this->consoleBase() !== '' && trim((string) env('CONSOLE_API_TOKEN', '')) !== '') {
            return 'console';
        }
        if ($mode === 'smtp' && trim((string) env('ENGAGE_SMTP_HOST', '')) !== '' && trim((string) env('ENGAGE_MAIL_FROM', '')) !== '') {
            return 'smtp';
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->channel() !== null;
    }

    /** Human-readable status for /health and the staff UI. */
    public function status(): string
    {
        $mode = strtolower(trim((string) env('ENGAGE_SELLER_MAILER', '')));
        if ($this->isConfigured()) {
            return 'ok (' . $this->channel() . ')';
        }
        if ($mode === '') {
            return 'ENGAGE_SELLER_MAILER not set — seller emails are not sent; activation / reset links are shown once to staff';
        }

        return "ENGAGE_SELLER_MAILER={$mode} but its settings are incomplete — seller emails are not sent";
    }

    /**
     * @param array<string,scalar|null> $vars
     * @return array{sent:bool, channel:?string, reason:?string}
     */
    public function send(string $trigger, string $email, string $name, array $vars, string $idempotencyKey): array
    {
        $channel = $this->channel();
        if ($channel === null) {
            return ['sent' => false, 'channel' => null, 'reason' => 'not_configured'];
        }
        try {
            return $channel === 'console'
                ? $this->viaConsole($trigger, $email, $name, $vars, $idempotencyKey)
                : $this->viaSmtp($trigger, $email, $name, $vars);
        } catch (\Throwable $e) {
            log_message('error', "Seller mail {$trigger} failed: " . $e->getMessage());

            return ['sent' => false, 'channel' => $channel, 'reason' => 'error'];
        }
    }

    /** @return array{sent:bool, channel:string, reason:?string} */
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
            return ['sent' => true, 'channel' => 'console', 'reason' => null];
        }
        $reason = is_array($json) ? (string) ($json['data']['skipped_reason'] ?? $json['error']['code'] ?? $json['code'] ?? ('http_' . (int) ($res['status'] ?? 0)))
            : 'http_' . (int) ($res['status'] ?? 0);
        log_message('warning', "Seller mail {$trigger} not sent by Console: {$reason}");

        return ['sent' => false, 'channel' => 'console', 'reason' => $reason !== '' ? $reason : 'not_sent'];
    }

    /** @return array{sent:bool, channel:string, reason:?string} */
    private function viaSmtp(string $trigger, string $email, string $name, array $vars): array
    {
        [$subject, $text] = self::compose($trigger, $vars);
        $mailer = \Config\Services::email([
            'protocol'   => 'smtp',
            'SMTPHost'   => (string) env('ENGAGE_SMTP_HOST', ''),
            'SMTPPort'   => (int) env('ENGAGE_SMTP_PORT', 587),
            'SMTPUser'   => (string) env('ENGAGE_SMTP_USER', ''),
            'SMTPPass'   => (string) env('ENGAGE_SMTP_PASS', ''),
            'SMTPCrypto' => (string) env('ENGAGE_SMTP_CRYPTO', 'tls'),
            'mailType'   => 'text',
        ], false);
        $mailer->setFrom((string) env('ENGAGE_MAIL_FROM', ''), (string) env('ENGAGE_MAIL_FROM_NAME', 'Aicountly Sellers'));
        $mailer->setTo($email);
        $mailer->setSubject($subject);
        $mailer->setMessage($text);
        $ok = $mailer->send(false);
        if (! $ok) {
            log_message('error', "Seller mail {$trigger} rejected by SMTP: " . substr(strip_tags((string) $mailer->printDebugger(['headers'])), 0, 300));
        }

        return ['sent' => (bool) $ok, 'channel' => 'smtp', 'reason' => $ok ? null : 'smtp_rejected'];
    }

    /** @return array{0:string, 1:string} subject, text body (SMTP only; Console uses its own templates). */
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

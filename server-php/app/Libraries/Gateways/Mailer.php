<?php

declare(strict_types=1);

namespace App\Libraries\Gateways;

use App\Libraries\Email\EmailDispatcher;

/**
 * Engage's transactional email — a facade over EmailDispatcher (copied from reach-aicountly).
 *
 * Providers come from Console's central registry (GET {CONSOLE_API_URL}/portal/email/providers,
 * cached; EmailProviderResolver). A normal send tries the primary, then each fallback; a resend
 * (opts['resend'] = true) tries the fallback first. When Console has no provider to give, nothing is
 * sent here; SellerMailer then falls back to Engage's own ENGAGE_SMTP_* settings.
 *
 * send() is true only when a provider accepted the message; getLastError() then says nothing,
 * otherwise it lists each provider's reason. getLastProvider() names the provider that sent it.
 * No method returns or logs a secret.
 */
final class Mailer
{
    /** @var array<string,mixed>|null */
    private static ?array $lastResult = null;

    public static function isConfigured(): bool
    {
        try {
            return EmailDispatcher::instance()->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Provider names only, e.g. ['line' => 'email via itwalk_api, fallback aicountly_smtp', 'source' => 'console', …].
     *
     * @return array{source:string, note:?string, line:string, providers:list<array{code:string,type:string,priority:int,role:string}>}
     */
    public static function providerSummary(): array
    {
        try {
            return EmailDispatcher::instance()->resolver()->summary();
        } catch (\Throwable $e) {
            return ['source' => 'none', 'note' => 'provider lookup failed', 'line' => 'email: provider lookup failed', 'providers' => []];
        }
    }

    public static function getLastError(): ?string
    {
        return self::$lastResult['error'] ?? null;
    }

    public static function getLastSendMode(): ?string
    {
        return self::$lastResult['mode'] ?? null;
    }

    public static function getLastSendUrl(): ?string
    {
        return self::$lastResult['url'] ?? null;
    }

    public static function getLastMessageId(): ?string
    {
        return self::$lastResult['id'] ?? null;
    }

    public static function getLastProvider(): ?string
    {
        return self::$lastResult['provider'] ?? null;
    }

    /** @return array<string,mixed>|null the last send's dispatcher result (codes and reasons, never secrets) */
    public static function lastResult(): ?array
    {
        return self::$lastResult;
    }

    /**
     * @param string|array<int,array{email:string,name?:string}> $to
     * @param array{resend?:bool, headers?:array<string,string>, reply_to?:string, from_name?:string} $opts
     */
    public static function send(string|array $to, string $subject, string $body, ?string $plainText = null, array $opts = []): bool
    {
        try {
            self::$lastResult = EmailDispatcher::instance()->send($to, $subject, $body, $plainText, $opts);
        } catch (\Throwable $e) {
            log_message('error', 'Mailer::send failed — ' . $e->getMessage());
            self::$lastResult = ['ok' => false, 'error' => 'mailer error', 'attempts' => []];
        }

        return (bool) self::$lastResult['ok'];
    }
}

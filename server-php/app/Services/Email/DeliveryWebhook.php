<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Libraries\Email\AicountlyEmailConfig;

/**
 * Signed delivery events from the AICOUNTLY Email API (docs/DELIVERY_API.md § Events — webhook).
 *
 *   POST /api/v1/inbound/email/delivery-events
 *   X-Aicountly-Timestamp: <unix seconds>
 *   X-Aicountly-Signature: sha256=hex(HMAC_SHA256(AICOUNTLY_EMAIL_WEBHOOK_SECRET, timestamp + "." + raw_body))
 *   {"event_id", "event", "delivery_id", "idempotency_key", "category", "recipient", "dsn", "bounce_class", "at"}
 *
 * 503 not_configured (no secret) · 401 invalid_signature / stale_timestamp (more than 5 minutes off) ·
 * 400 invalid_payload · 200 {event_id, result: applied|duplicate}. Events come at least once: a repeat of an
 * event_id answers 200 duplicate and changes nothing, so the sender stops retrying it.
 */
final class DeliveryWebhook
{
    public const MAX_SKEW_SECONDS = 300;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private ?AicountlyEmailConfig $config = null,
        private ?EmailDeliveryLedger $ledger = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Verify a signature; null when it is good, else the reason code. */
    public static function verify(string $secret, string $timestamp, string $signature, string $rawBody, int $now): ?string
    {
        if ($secret === '') {
            return 'not_configured';
        }
        $timestamp = trim($timestamp);
        if ($timestamp === '' || !ctype_digit($timestamp)) {
            return 'invalid_signature';
        }
        $given = strtolower(trim($signature));
        if (!str_starts_with($given, 'sha256=')) {
            return 'invalid_signature';
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $given)) {
            return 'invalid_signature';
        }
        if (abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return 'stale_timestamp';
        }

        return null;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    public function handle(string $rawBody, string $timestamp, string $signature): array
    {
        $config = $this->config ??= new AicountlyEmailConfig();
        $reason = self::verify($config->webhookSecret(), $timestamp, $signature, $rawBody, ($this->clock)());
        if ($reason === 'not_configured') {
            return self::fail(503, 'not_configured', 'AICOUNTLY_EMAIL_WEBHOOK_SECRET is not set.');
        }
        if ($reason !== null) {
            return self::fail(401, $reason, $reason === 'stale_timestamp'
                ? 'X-Aicountly-Timestamp is more than 5 minutes away from now.'
                : 'X-Aicountly-Signature does not match.');
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || !isset($event['event_id']) || !is_numeric($event['event_id']) || (int) $event['event_id'] <= 0
            || !is_string($event['event'] ?? null) || $event['event'] === '') {
            return self::fail(400, 'invalid_payload', 'Expected a delivery event with event_id and event.');
        }

        $result = ($this->ledger ??= new EmailDeliveryLedger())->applyEvent($event);
        if ($result === 'applied' && in_array($event['event'], ['bounced', 'complained', 'unsubscribed', 'suppressed'], true) && function_exists('log_message')) {
            log_message('notice', '[EmailDelivery] {event} delivery={id} class={cls}', [
                'event' => $event['event'], 'id' => (string) ($event['delivery_id'] ?? ''), 'cls' => (string) ($event['bounce_class'] ?? '-'),
            ]);
        }

        return ['status' => 200, 'body' => ['ok' => true, 'data' => ['event_id' => (int) $event['event_id'], 'result' => $result]]];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function fail(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'code' => $code, 'error' => $message]];
    }
}

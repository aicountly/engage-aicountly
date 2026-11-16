<?php

declare(strict_types=1);

namespace App\Services\Email;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Engage's record of mail sent through the AICOUNTLY Email delivery API, and of what happened to it.
 *
 *   engage_email_deliveries        one row per accepted message (unique idempotency_key, the Email API's
 *                                  delivery_id), its summary status and per-recipient state
 *                                  {address: {status, dsn, bounce_class, at}}
 *   engage_email_delivery_events   every signed webhook event once (event_id is the dedupe key)
 *
 * Event → status: queued→queued, submitted→sending, relayed→relayed, deferred→deferred, bounced→bounced,
 * complained→complained, unsubscribed→unsubscribed, suppressed→suppressed, expired→expired,
 * cancelled→cancelled, uncertain→uncertain; failover is recorded only. Events arrive at least once and
 * not always in order, so a state only moves to an equal or stronger one (RANK): a late "deferred" never
 * hides a "relayed", a late "relayed" never hides a "bounced".
 *
 * Hard bounces, complaints and unsubscribes are already suppressed by the mail server; Engage keeps them
 * here (status + bounce_class per recipient). Engage has no marketing consent of its own to mirror them into.
 * No message content is stored.
 */
final class EmailDeliveryLedger
{
    public const STATUS_FOR_EVENT = [
        'queued'       => 'queued',
        'submitted'    => 'sending',
        'relayed'      => 'relayed',
        'deferred'     => 'deferred',
        'bounced'      => 'bounced',
        'complained'   => 'complained',
        'unsubscribed' => 'unsubscribed',
        'suppressed'   => 'suppressed',
        'expired'      => 'expired',
        'cancelled'    => 'cancelled',
        'uncertain'    => 'uncertain',
        'failover'     => null,
    ];

    /** Higher wins; equal rank: the later event wins. */
    public const RANK = [
        'queued'       => 0,
        'sending'      => 1,
        'deferred'     => 1,
        'uncertain'    => 1,
        'relayed'      => 2,
        'expired'      => 2,
        'cancelled'    => 2,
        'bounced'      => 3,
        'suppressed'   => 3,
        'complained'   => 4,
        'unsubscribed' => 4,
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** The status an event leads to, or null when it does not change state (failover, unknown). */
    public static function statusFor(string $event): ?string
    {
        return self::STATUS_FOR_EVENT[$event] ?? null;
    }

    /** The state after $next arrives on top of $current. */
    public static function merge(?string $current, ?string $next): ?string
    {
        if ($next === null) {
            return $current;
        }
        if ($current === null || !isset(self::RANK[$current])) {
            return $next;
        }

        return (self::RANK[$next] ?? 0) >= self::RANK[$current] ? $next : $current;
    }

    /** Transport callback: record an accepted message; never throws. */
    public static function recordQuietly(array $record): void
    {
        try {
            (new self())->recordSent($record);
        } catch (\Throwable $e) {
            if (function_exists('log_message')) {
                log_message('warning', '[EmailDeliveryLedger] delivery not recorded: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array{idempotency_key:string, delivery_id:string, category:string, cmp_id?:?int, template_ref?:?string,
     *              recipients:list<string>, status?:?string} $r
     */
    public function recordSent(array $r): void
    {
        $recipients = [];
        foreach ($r['recipients'] as $addr) {
            $recipients[strtolower($addr)] = ['status' => 'queued'];
        }
        $this->db()->query(
            'INSERT INTO engage_email_deliveries (idempotency_key, delivery_id, category, cmp_id, template_ref, recipient_count, recipients, status)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?)
             ON CONFLICT (idempotency_key) DO UPDATE
                SET delivery_id = COALESCE(engage_email_deliveries.delivery_id, EXCLUDED.delivery_id),
                    category = EXCLUDED.category, cmp_id = COALESCE(EXCLUDED.cmp_id, engage_email_deliveries.cmp_id),
                    template_ref = COALESCE(EXCLUDED.template_ref, engage_email_deliveries.template_ref),
                    recipient_count = GREATEST(engage_email_deliveries.recipient_count, EXCLUDED.recipient_count),
                    recipients = EXCLUDED.recipients || engage_email_deliveries.recipients,
                    updated_at = NOW()',
            [
                $r['idempotency_key'], $r['delivery_id'], $r['category'], $r['cmp_id'] ?? null, $r['template_ref'] ?? null,
                count($recipients), (string) json_encode((object) $recipients, JSON_UNESCAPED_SLASHES), $r['status'] ?? 'queued',
            ]
        );
    }

    /**
     * Apply one verified webhook event exactly once.
     *
     * @param array<string,mixed> $e {event_id, event, delivery_id, idempotency_key, category, recipient, dsn, bounce_class, at}
     * @return 'applied'|'duplicate'
     */
    public function applyEvent(array $e): string
    {
        $db = $this->db();
        $db->transBegin();
        try {
            $inserted = $db->query(
                'INSERT INTO engage_email_delivery_events (event_id, event, delivery_id, idempotency_key, category, recipient, dsn, bounce_class, occurred_at, payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
                 ON CONFLICT (event_id) DO NOTHING RETURNING event_id',
                [
                    (int) $e['event_id'], (string) $e['event'], self::str($e['delivery_id'] ?? null, 64), self::str($e['idempotency_key'] ?? null, 160),
                    self::str($e['category'] ?? null, 16), self::str(isset($e['recipient']) ? strtolower((string) $e['recipient']) : null, 254),
                    self::str($e['dsn'] ?? null, 16), self::str($e['bounce_class'] ?? null, 32), self::timestamp($e['at'] ?? null),
                    (string) json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]
            )->getResultArray();
            if ($inserted === []) {
                $db->transCommit();

                return 'duplicate';
            }

            $this->applyToDelivery($e);
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();

            throw $ex;
        }

        return 'applied';
    }

    /** @param array<string,mixed> $e */
    private function applyToDelivery(array $e): void
    {
        $db         = $this->db();
        $deliveryId = self::str($e['delivery_id'] ?? null, 64);
        $idem       = self::str($e['idempotency_key'] ?? null, 160);
        if ($deliveryId === null && $idem === null) {
            return;
        }

        $row = null;
        if ($deliveryId !== null) {
            $row = $db->query('SELECT id, status, recipients FROM engage_email_deliveries WHERE delivery_id = ? FOR UPDATE', [$deliveryId])->getRowArray();
        }
        if ($row === null && $idem !== null) {
            $row = $db->query('SELECT id, status, recipients FROM engage_email_deliveries WHERE idempotency_key = ? FOR UPDATE', [$idem])->getRowArray();
        }
        if ($row === null) {
            // Sent before the ledger existed, or the send-time record failed: start the row from the event.
            $db->query(
                "INSERT INTO engage_email_deliveries (idempotency_key, delivery_id, category, recipient_count, recipients, status)
                 VALUES (?, ?, ?, 0, '{}'::jsonb, 'queued') ON CONFLICT (idempotency_key) DO NOTHING",
                [$idem ?? ('delivery:' . $deliveryId), $deliveryId, self::str($e['category'] ?? null, 16) ?? 'transactional']
            );
            $row = $db->query('SELECT id, status, recipients FROM engage_email_deliveries WHERE idempotency_key = ? FOR UPDATE', [$idem ?? ('delivery:' . $deliveryId)])->getRowArray();
            if ($row === null) {
                return;
            }
        }

        $event  = (string) $e['event'];
        $next   = self::statusFor($event);
        $status = self::merge($row['status'] !== null ? (string) $row['status'] : null, $next);

        $recipients = json_decode((string) ($row['recipients'] ?? '{}'), true);
        $recipients = is_array($recipients) ? $recipients : [];
        $addr = isset($e['recipient']) && $e['recipient'] !== '' ? strtolower((string) $e['recipient']) : null;
        if ($addr !== null && $next !== null) {
            $cur = is_array($recipients[$addr] ?? null) ? $recipients[$addr] : [];
            $merged = self::merge(isset($cur['status']) ? (string) $cur['status'] : null, $next);
            if ($merged === $next) {
                $cur = ['status' => $next, 'at' => $e['at'] ?? null]
                    + array_filter(['dsn' => $e['dsn'] ?? null, 'bounce_class' => $e['bounce_class'] ?? null], static fn ($v) => $v !== null && $v !== '');
            }
            $recipients[$addr] = $cur;
        }

        $db->query(
            'UPDATE engage_email_deliveries
                SET status = ?, last_event = ?, last_event_at = ?, recipients = ?::jsonb,
                    delivery_id = COALESCE(delivery_id, ?), recipient_count = GREATEST(recipient_count, ?), updated_at = NOW()
              WHERE id = ?',
            [
                $status, $event, self::timestamp($e['at'] ?? null), (string) json_encode((object) $recipients, JSON_UNESCAPED_SLASHES),
                $deliveryId, count($recipients), (int) $row['id'],
            ]
        );
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || !is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : substr($s, 0, $max);
    }

    private static function timestamp(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $t = strtotime($v);

        return $t === false ? null : gmdate('Y-m-d H:i:sP', $t);
    }
}

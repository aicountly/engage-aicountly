<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Libraries\Seller\Money;
use App\Libraries\Seller\SellerCrypto;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Engage → Finance payout batches (contract "Weekly payouts"):
 *
 *   POST {FINANCE_API_BASE_URL}/v1/inbound/engage/payout-batches
 *   X-Engage-Key: {FINANCE_OUTBOUND_KEY}      (Finance checks it against its FINANCE_INBOUND_ENGAGE_KEY)
 *   Idempotency-Key: {batch_ref}
 *   {batch_ref, period_start, period_end, currency:"INR",
 *    items:[{item_ref, seller_code, payee:{legal_name, pan, gstin|null, entity_type,
 *            bank:{account_holder, account_number, ifsc}}, gross_amount, entries:[entry ids]}]}
 *   → 201 {batch_id, status:"received"}   (Finance is idempotent on batch_ref)
 *
 * The batch is a DRAFT: a person in Finance reviews it, computes TDS / GST / net and releases it.
 *
 * One outbox row per run (engage_finance_payout_outbox). The request body is never stored: the payee's PAN,
 * GSTIN and bank account are decrypted from KYC (ENGAGE_DATA_KEY) only while the body is built, and the body
 * lives only for the HTTP call. Delivery is attempted right after the run and then by
 * `php spark engage:finance-payouts-deliver` (every 5 minutes): 2xx with Finance's JSON batch_id → delivered (payouts
 * draft_sent); 400 / 409 / 422, or a 2xx that is not Finance's receipt (e.g. FINANCE_API_BASE_URL without /api hitting
 * the SPA fallback) → rejected (not retried — fix and "Re-send to Finance"); anything else → retry with back-off
 * 2, 4, 8 … minutes (at most 6 h), dead after 12 attempts.
 */
class FinancePayoutOutbox
{
    public const PATH = '/v1/inbound/engage/payout-batches';
    public const MAX_ATTEMPTS = 12;

    private string $baseUrl;
    private string $key;
    private ?BaseConnection $db;
    private SellerCrypto $crypto;
    /** @var callable(string, list<string>, string): array{status:int, body:string|false|null, error?:string} */
    private $transport;

    public function __construct(?callable $transport = null, ?string $baseUrl = null, ?string $key = null, ?BaseConnection $db = null, ?SellerCrypto $crypto = null)
    {
        $this->baseUrl   = rtrim($baseUrl ?? trim((string) env('FINANCE_API_BASE_URL', '')), '/');
        $this->key       = $key ?? trim((string) env('FINANCE_OUTBOUND_KEY', ''));
        $this->db        = $db;
        $this->crypto    = $crypto ?? new SellerCrypto();
        $this->transport = $transport ?? [self::class, 'curlPost'];
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && strlen($this->key) >= 32;
    }

    public function status(): string
    {
        if ($this->isConfigured()) {
            return 'ok';
        }

        return $this->baseUrl === '' ? 'FINANCE_API_BASE_URL not set — weekly payout runs fail closed (nothing is sent to Finance)'
            : 'FINANCE_OUTBOUND_KEY missing or shorter than 32 characters — weekly payout runs fail closed';
    }

    public function enqueue(int $runId, string $batchRef): void
    {
        $this->db()->query('INSERT INTO engage_finance_payout_outbox (run_id, batch_ref) VALUES (?, ?) ON CONFLICT (run_id) DO NOTHING', [$runId, $batchRef]);
    }

    /**
     * Deliver every due batch.
     *
     * @return array{delivered:int, rejected:int, retry:int, dead:int}
     */
    public function deliverDue(int $max = 20): array
    {
        $out = ['delivered' => 0, 'rejected' => 0, 'retry' => 0, 'dead' => 0];
        for ($i = 0; $i < $max; $i++) {
            $row = $this->db()->query(
                "UPDATE engage_finance_payout_outbox SET next_attempt_at = NOW() + INTERVAL '10 minutes'
                  WHERE id = (SELECT id FROM engage_finance_payout_outbox WHERE status = 'pending' AND next_attempt_at <= NOW()
                               ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED)
                  RETURNING *"
            )->getRowArray();
            if (! $row) {
                break;
            }
            $out[$this->attempt($row)]++;
        }

        return $out;
    }

    /** Try one run's batch now (right after the run, or on "Re-send"). Returns delivered|rejected|retry|dead|skipped. */
    public function deliverRun(int $runId): string
    {
        $row = $this->db()->query(
            "UPDATE engage_finance_payout_outbox SET next_attempt_at = NOW() + INTERVAL '10 minutes'
              WHERE run_id = ? AND status = 'pending' RETURNING *",
            [$runId]
        )->getRowArray();

        return $row ? $this->attempt($row) : 'skipped';
    }

    /** "Re-send to Finance": a rejected / dead / pending batch goes back to pending with a fresh attempt budget. */
    public function resend(int $runId): bool
    {
        $n = $this->db()->query(
            "UPDATE engage_finance_payout_outbox SET status = 'pending', attempts = 0, next_attempt_at = NOW()
              WHERE run_id = ? AND status IN ('pending','rejected','dead') RETURNING id",
            [$runId]
        )->getResultArray();

        return $n !== [];
    }

    /** @return array<string,mixed>|null */
    public function forRun(int $runId): ?array
    {
        return $this->db()->table('engage_finance_payout_outbox')->where('run_id', $runId)->get()->getRowArray() ?: null;
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        $out = ['pending' => 0, 'delivered' => 0, 'rejected' => 0, 'dead' => 0, 'cancelled' => 0];
        foreach ($this->db()->query('SELECT status, COUNT(*) AS n FROM engage_finance_payout_outbox GROUP BY status')->getResultArray() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * The request body for a run, with payee details decrypted from KYC. Only ever passed to the transport.
     *
     * @return array<string,mixed>
     */
    public function buildPayload(int $runId): array
    {
        $db = $this->db();
        $run = $db->table('engage_payout_runs')->where('id', $runId)->get()->getRowArray();
        if (! $run) {
            throw new \RuntimeException("Payout run {$runId} not found.");
        }
        $rows = $db->query(
            "SELECT p.*, k.legal_name, k.entity_type AS kyc_entity_type, k.pan_enc, k.gstin_enc, k.bank_account_holder, k.bank_account_enc, k.bank_ifsc, k.status AS kyc_status
               FROM engage_seller_payouts p LEFT JOIN engage_seller_kyc k ON k.seller_id = p.seller_id
              WHERE p.run_id = ? AND p.status IN ('queued','draft_sent') ORDER BY p.id",
            [$runId]
        )->getResultArray();
        if ($rows === []) {
            throw new \RuntimeException('This batch has no payouts left to send.');
        }
        if (! $this->crypto->isConfigured()) {
            throw new \RuntimeException('ENGAGE_DATA_KEY is not configured — payee details cannot be read.');
        }
        $items = [];
        foreach ($rows as $p) {
            if (($p['kyc_status'] ?? null) !== 'kyc_verified' || empty($p['pan_enc']) || empty($p['bank_account_enc'])) {
                throw new \RuntimeException("Seller {$p['seller_code']} has no verified KYC with PAN and bank account.");
            }
            $items[] = [
                'item_ref'     => $p['item_ref'],
                'seller_code'  => $p['seller_code'],
                'payee'        => [
                    'legal_name'  => $p['legal_name'],
                    'pan'         => $this->crypto->decrypt((string) $p['pan_enc']),
                    'gstin'       => ! empty($p['gstin_enc']) ? $this->crypto->decrypt((string) $p['gstin_enc']) : null,
                    'entity_type' => $p['kyc_entity_type'],
                    'bank'        => [
                        'account_holder' => $p['bank_account_holder'],
                        'account_number' => $this->crypto->decrypt((string) $p['bank_account_enc']),
                        'ifsc'           => $p['bank_ifsc'],
                    ],
                ],
                'gross_amount' => Money::format(Money::toPaise((string) $p['gross_amount'])),
                'entries'      => array_map('intval', json_decode((string) $p['entry_ids'], true) ?: []),
            ];
        }

        return [
            'batch_ref'    => $run['batch_ref'],
            'period_start' => $run['period_start'],
            'period_end'   => $run['period_end'],
            'currency'     => 'INR',
            'items'        => $items,
        ];
    }

    /** @param array<string,mixed> $row outbox row (already claimed). */
    private function attempt(array $row): string
    {
        $runId = (int) $row['run_id'];
        if (! $this->isConfigured()) {
            $this->db()->query('UPDATE engage_finance_payout_outbox SET last_error = ?, next_attempt_at = NOW() + INTERVAL \'5 minutes\' WHERE id = ?', [$this->status(), $row['id']]);

            return 'retry';
        }
        try {
            $payload = $this->buildPayload($runId);
        } catch (\Throwable $e) {
            return $this->failed($row, 0, 'not sent: ' . $e->getMessage(), false);
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        unset($payload);
        try {
            $res = ($this->transport)($this->baseUrl . self::PATH, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Engage-Key: ' . $this->key,
                'Idempotency-Key: ' . $row['batch_ref'],
                'X-Source: engage.aicountly.org',
            ], $body);
        } catch (\Throwable $e) {
            $res = ['status' => 0, 'body' => null, 'error' => $e->getMessage()];
        }
        $body = '';
        $status = (int) ($res['status'] ?? 0);
        $raw = is_string($res['body'] ?? null) ? $res['body'] : null;
        $json = $raw !== null ? json_decode($raw, true) : null;
        $is2xx = $status >= 200 && $status < 300;
        if ($is2xx) {
            // Only Finance's receipt counts: JSON carrying the batch_id (201 {batch_id, status:"received"}, or 200
            // {..., duplicate:true} for a replay). A 2xx without it is not Finance — typically FINANCE_API_BASE_URL
            // without /api, which lands on the web app's SPA fallback (200 text/html) — so nothing is "sent".
            $data = is_array($json) ? (is_array($json['data'] ?? null) ? $json['data'] : $json) : [];
            $batchId = is_scalar($data['batch_id'] ?? null) && (string) $data['batch_id'] !== '' ? substr((string) $data['batch_id'], 0, 96) : null;
            $refused = is_array($json) && (($json['ok'] ?? true) === false || ($json['success'] ?? true) === false);
            if ($batchId !== null && ! $refused) {
                $this->delivered($row, $status, $batchId);

                return 'delivered';
            }
            if (! $refused) {
                return $this->failed($row, $status, "http_status={$status} but the answer is not Finance's batch receipt (no JSON batch_id"
                    . (is_array($json) ? '' : '; got ' . (is_string($res['content_type'] ?? null) && $res['content_type'] !== '' ? $res['content_type'] : 'a non-JSON body'))
                    . ') — check FINANCE_API_BASE_URL (production: https://finance.aicountly.org/api, with /api). Fix it and Re-send.', true);
            }
        }
        $err = (string) ($res['error'] ?? '');
        if ($err === '') {
            $msg = is_array($json) ? (string) ($json['error'] ?? $json['message'] ?? '') : (string) substr((string) $raw, 0, 200);
            $err = 'http_status=' . $status . ($msg !== '' ? ' ' . $msg : '');
        }

        return $this->failed($row, $status, $err, in_array($status, [400, 409, 422], true));
    }

    private function delivered(array $row, int $status, ?string $batchId): void
    {
        $db = $this->db();
        $runId = (int) $row['run_id'];
        $db->transStart();
        $db->query("UPDATE engage_finance_payout_outbox SET status = 'delivered', delivered_at = NOW(), attempts = attempts + 1,
                           last_http_status = ?, last_error = NULL, finance_batch_id = ? WHERE id = ?", [$status, $batchId, $row['id']]);
        $sent = $db->query("UPDATE engage_seller_payouts SET status = 'draft_sent', sent_at = NOW(), finance_batch_ref = ?, updated_at = NOW()
                             WHERE run_id = ? AND status = 'queued' RETURNING id", [$batchId, $runId])->getResultArray();
        foreach ($sent as $p) {
            $db->query("INSERT INTO engage_seller_payout_events (payout_id, source, from_status, to_status, payload) VALUES (?, 'engage', 'queued', 'draft_sent', CAST(? AS JSONB))",
                [(int) $p['id'], json_encode(['finance_batch_id' => $batchId, 'http_status' => $status])]);
        }
        $db->query("UPDATE engage_payout_runs SET status = 'sent', sent_at = NOW(), finance_batch_id = ?, error = NULL, updated_at = NOW()
                     WHERE id = ? AND status IN ('queued','delivery_failed')", [$batchId, $runId]);
        $db->transComplete();
        log_message('info', "Payout batch {$row['batch_ref']} delivered to Finance (HTTP {$status}, batch_id " . ($batchId ?? '—') . ')');
    }

    private function failed(array $row, int $status, string $err, bool $permanent): string
    {
        $db = $this->db();
        $attempts = (int) $row['attempts'] + 1;
        $dead = ! $permanent && $attempts >= self::MAX_ATTEMPTS;
        $state = $permanent ? 'rejected' : ($dead ? 'dead' : 'pending');
        $delay = min(360, 2 ** min($attempts, 9));
        $err = substr($err, 0, 1000);
        $db->query('UPDATE engage_finance_payout_outbox SET status = ?, attempts = ?, last_http_status = ?, last_error = ?,
                           next_attempt_at = NOW() + make_interval(mins => ?) WHERE id = ?',
            [$state, $attempts, $status ?: null, $err, $delay, $row['id']]);
        if ($state !== 'pending') {
            $db->query("UPDATE engage_payout_runs SET status = 'delivery_failed', error = ?, updated_at = NOW() WHERE id = ? AND status = 'queued'",
                ['Finance delivery ' . $state . ': ' . $err, (int) $row['run_id']]);
        } else {
            $db->query('UPDATE engage_payout_runs SET error = ?, updated_at = NOW() WHERE id = ? AND status = \'queued\'',
                ["Finance delivery attempt {$attempts} failed (will retry): {$err}", (int) $row['run_id']]);
        }
        log_message($state === 'pending' ? 'warning' : 'critical', sprintf('Payout batch %s to Finance failed (status %d): %s — %s',
            $row['batch_ref'], $status, substr($err, 0, 200), $state === 'pending' ? "attempt {$attempts}, retry in {$delay} min" : $state));

        return $state === 'pending' ? 'retry' : $state;
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** @return array{status:int, body:string|false, error:string, content_type:string} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => $raw, 'error' => $err, 'content_type' => $type];
    }
}

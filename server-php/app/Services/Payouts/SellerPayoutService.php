<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\Money;
use App\Libraries\Seller\PayoutWeek;
use App\Libraries\Seller\SellerCrypto;
use App\Models\SettingsModel;
use App\Services\Seller\SellerLeadService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use InvalidArgumentException;

/**
 * Weekly seller payouts (docs/SELLER_PROGRAM.md § 6). Engage compiles DRAFTS; finance.aicountly.org reviews,
 * computes TDS (194H) / GST / net, releases the money and reports back. Engage never marks anything paid on its
 * own: only Finance's `paid` status with a UTR does.
 *
 * Run (one per ISO week, `php spark engage:seller-payout-run`, Monday 10:00 IST for the week that just ended):
 *   - commission entries `approved` before the week ended (cutoff = Monday 00:00 IST) and not in a payout;
 *   - per seller that is `active` with KYC `kyc_verified` (PAN + bank on file); others are skipped and roll over;
 *   - gross below the minimum (setting seller_payout_min_threshold, default 500.00) rolls over;
 *   - one payout per seller (PO-YYYY-WW-NNNNNN = Finance item_ref), entries → `in_payout` with that ref;
 *   - one batch (PB-YYYY-WW) queued in engage_finance_payout_outbox and sent at once.
 *   A week's run is final once it built (empty / queued / sent / delivery_failed / cancelled); only a `failed`
 *   run (nothing was written) can be run again. Finance or the KYC key unconfigured → the run fails closed.
 *
 * Finance status (POST v1/inbound/finance/payout-status): approved | on_hold | paid | failed | reversed | cancelled.
 *   paid needs a UTR → entries `paid`; failed / reversed / cancelled → entries back to `approved` (out of the
 *   payout) so a later run picks them up. Repeats are idempotent.
 */
class SellerPayoutService implements SellerPayoutGateway
{
    public const FINANCE_STATUSES = ['approved', 'on_hold', 'paid', 'failed', 'reversed', 'cancelled'];
    /** from → allowed Finance statuses */
    private const TRANSITIONS = [
        'queued'     => ['approved', 'on_hold', 'paid', 'failed', 'reversed', 'cancelled'],
        'draft_sent' => ['approved', 'on_hold', 'paid', 'failed', 'reversed', 'cancelled'],
        'on_hold'    => ['approved', 'on_hold', 'paid', 'failed', 'reversed', 'cancelled'],
        'approved'   => ['approved', 'on_hold', 'paid', 'failed', 'reversed', 'cancelled'],
        'paid'       => ['reversed'],
        'failed'     => [],
        'reversed'   => [],
        'cancelled'  => [],
    ];
    private const RETURNING = ['failed', 'reversed', 'cancelled'];
    private const LOCK_KEY = 4815162342;

    private ?BaseConnection $db;
    private ?FinancePayoutOutbox $outbox;
    private SellerCrypto $crypto;

    public function __construct(?BaseConnection $db = null, ?FinancePayoutOutbox $outbox = null, ?SellerCrypto $crypto = null)
    {
        $this->db     = $db;
        $this->outbox = $outbox;
        $this->crypto = $crypto ?? new SellerCrypto();
    }

    public function outbox(): FinancePayoutOutbox
    {
        return $this->outbox ??= Services::financePayouts();
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function minPayoutPaise(): int
    {
        try {
            return Money::toPaise((string) (new SettingsModel())->getSetting('seller_payout_min_threshold', '500.00'));
        } catch (\Throwable $e) {
            return 50000;
        }
    }

    // ------------------------------------------------------------------ run

    /**
     * Per-seller groups of payable entries (approved before $cutoffTs, not in a payout) with eligibility.
     *
     * @return list<array<string,mixed>>
     */
    private function collect(int $cutoffTs, int $minPaise, bool $lock): array
    {
        $rows = $this->db()->query(
            "SELECT e.id, e.seller_id, e.amount, s.seller_code, s.name AS seller_name, s.status AS seller_status, s.kyc_status,
                    k.status AS kyc_row_status, (k.pan_enc IS NOT NULL AND k.bank_account_enc IS NOT NULL AND k.bank_ifsc IS NOT NULL) AS kyc_complete
               FROM engage_commission_entries e
               JOIN engage_sellers s ON s.id = e.seller_id
               LEFT JOIN engage_seller_kyc k ON k.seller_id = s.id
              WHERE e.status = 'approved' AND e.payout_item_ref IS NULL AND e.approved_at < ?
              ORDER BY s.seller_code, e.id" . ($lock ? ' FOR UPDATE OF e' : ''),
            [Clock::at($cutoffTs)]
        )->getResultArray();
        $groups = [];
        foreach ($rows as $r) {
            $sid = (int) $r['seller_id'];
            $groups[$sid] ??= [
                'seller_id' => $sid, 'seller_code' => $r['seller_code'], 'seller_name' => $r['seller_name'],
                'seller_status' => $r['seller_status'], 'kyc_status' => $r['kyc_status'],
                'kyc_ok' => $r['kyc_status'] === 'kyc_verified' && $r['kyc_row_status'] === 'kyc_verified' && in_array($r['kyc_complete'], [true, 't', 1, '1'], true),
                'paise' => 0, 'entry_ids' => [],
            ];
            $groups[$sid]['paise'] += Money::toPaise((string) $r['amount']);
            $groups[$sid]['entry_ids'][] = (int) $r['id'];
        }
        foreach ($groups as &$g) {
            [$g['included'], $g['skip_reason']] = match (true) {
                $g['seller_status'] !== 'active' => [false, 'seller_' . $g['seller_status']],
                ! $g['kyc_ok']                   => [false, 'kyc_not_verified'],
                $g['paise'] <= 0 || $g['paise'] < $minPaise => [false, 'below_minimum'],
                default                          => [true, null],
            };
        }
        unset($g);

        return array_values($groups);
    }

    private static function skipLabel(?string $reason): ?string
    {
        return match ($reason) {
            null               => null,
            'below_minimum'    => 'Below the minimum payout — rolls over to a later week',
            'kyc_not_verified' => 'KYC not verified — held until it is',
            default            => 'Seller is ' . substr($reason, 7) . ' — held',
        };
    }

    private static function groupView(array $g): array
    {
        return [
            'seller_id'     => $g['seller_id'],
            'seller_code'   => $g['seller_code'],
            'seller_name'   => $g['seller_name'],
            'seller_status' => $g['seller_status'],
            'kyc_status'    => $g['kyc_status'],
            'gross_amount'  => Money::format($g['paise']),
            'entries_count' => count($g['entry_ids']),
            'entry_ids'     => $g['entry_ids'],
            'included'      => $g['included'],
            'skip_reason'   => $g['skip_reason'],
            'skip_label'    => self::skipLabel($g['skip_reason']),
        ];
    }

    /**
     * Dry run: what the run for $weekCode (default: the current week, i.e. next Monday's run) would include,
     * as of now. Writes nothing.
     *
     * @return array<string,mixed>
     */
    public function preview(?string $weekCode = null, ?int $now = null): array
    {
        $now ??= time();
        $w = $weekCode !== null && $weekCode !== '' ? PayoutWeek::parse($weekCode) : PayoutWeek::current($now);
        $cutoff = min($now, $w->endsAt());
        $min = self::minPayoutPaise();
        $groups = $this->collect($cutoff, $min, false);
        $inc = array_values(array_filter($groups, static fn (array $g): bool => $g['included']));
        $sum = static fn (array $gs): string => Money::format(array_sum(array_column($gs, 'paise')));
        $existing = $this->db()->table('engage_payout_runs')->where('iso_week', $w->code())->get()->getRowArray();

        return [
            'dry_run'          => true,
            'week'             => $w->code(),
            'batch_ref'        => $w->batchRef(),
            'period_start'     => $w->periodStart(),
            'period_end'       => $w->periodEnd(),
            'cutoff_at'        => Clock::iso(Clock::at($cutoff)),
            'week_ended'       => $w->hasEnded($now),
            'scheduled_run_at' => $w->scheduledRunAt()->format(DATE_ATOM),
            'min_payout'       => Money::format($min),
            'existing_run'     => $existing ? $this->runView($existing) : null,
            'payouts_count'    => count($inc),
            'gross_amount'     => $sum($inc),
            'entries_count'    => array_sum(array_map(static fn (array $g): int => count($g['entry_ids']), $inc)),
            'held_amount'      => $sum(array_filter($groups, static fn (array $g): bool => ! $g['included'])),
            'sellers'          => array_map([self::class, 'groupView'], $groups),
            'finance'          => $this->outbox()->status(),
            'note'             => $w->hasEnded($now) ? null : 'The week is still running: commission approved before it ends is added; nothing is reserved by this preview.',
        ];
    }

    /** SellerPayoutGateway: build (and send) the run for the week containing $periodStart. */
    public function buildWeeklyRun(string $periodStart, string $periodEnd): array
    {
        $ts = strtotime($periodStart . ' 12:00:00 Asia/Kolkata');
        if ($ts === false) {
            throw new InvalidArgumentException('Bad period_start.');
        }
        $r = $this->run(PayoutWeek::containing($ts)->code());
        $run = $r['data']['run'] ?? [];

        return ['batch_ref' => (string) ($run['batch_ref'] ?? ''), 'items' => (int) ($run['payouts_count'] ?? 0), 'gross_amount' => (string) ($run['gross_amount'] ?? '0.00')];
    }

    /**
     * The real run for a week (default: the last week that ended). Idempotent per week.
     *
     * @return array{status:int, data?:array, error?:string, code?:string}
     */
    public function run(?string $weekCode = null, string $trigger = 'schedule', ?int $staffId = null, bool $deliver = true, ?int $now = null): array
    {
        $now ??= time();
        try {
            $w = $weekCode !== null && $weekCode !== '' ? PayoutWeek::parse($weekCode) : PayoutWeek::lastCompleted($now);
        } catch (InvalidArgumentException $e) {
            return ['status' => 422, 'error' => $e->getMessage(), 'details' => ['week' => $e->getMessage()]];
        }
        if (! $w->hasEnded($now)) {
            return ['status' => 422, 'error' => "Week {$w->code()} has not ended yet (it ends " . date('j M Y', $w->endsAt() - 1) . ' 23:59 IST). Use the preview to see what it would include.',
                'details' => ['week' => 'Week not ended.']];
        }
        $db = $this->db();
        $db->transBegin();
        try {
            $db->query('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            $existing = $db->query('SELECT * FROM engage_payout_runs WHERE iso_week = ? FOR UPDATE', [$w->code()])->getRowArray();
            if ($existing && $existing['status'] !== 'failed') {
                $db->transRollback();

                return ['status' => 200, 'data' => ['result' => 'exists', 'run' => $this->runView($existing)]];
            }
            $later = $db->query('SELECT iso_week FROM engage_payout_runs WHERE period_start > ? AND status <> ? ORDER BY period_start DESC LIMIT 1', [$w->periodStart(), 'failed'])->getRow();
            if ($later) {
                $db->transRollback();

                return ['status' => 409, 'error' => "A later week ({$later->iso_week}) already has a run; commission left from {$w->code()} is in it or will be in the next one."];
            }
            $min = self::minPayoutPaise();
            $base = [
                'batch_ref' => $w->batchRef(), 'period_start' => $w->periodStart(), 'period_end' => $w->periodEnd(),
                'cutoff_at' => Clock::at($w->endsAt()), 'status' => 'running', 'trigger_source' => $trigger === 'manual' ? 'manual' : 'schedule',
                'triggered_by' => $staffId, 'min_payout' => Money::format($min), 'error' => null, 'started_at' => Clock::now(), 'finished_at' => null, 'updated_at' => Clock::now(),
            ];
            if ($existing) {
                $db->table('engage_payout_runs')->where('id', (int) $existing['id'])->update($base + ['attempts' => (int) $existing['attempts'] + 1]);
                $runId = (int) $existing['id'];
            } else {
                $db->table('engage_payout_runs')->insert($base + ['iso_week' => $w->code()]);
                $runId = (int) $db->insertID();
            }

            // Fail closed: nothing is reserved for a batch that cannot be sent.
            $blocker = ! $this->outbox()->isConfigured() ? $this->outbox()->status()
                : (! $this->crypto->isConfigured() ? 'ENGAGE_DATA_KEY not set — payee details cannot be read for Finance' : null);
            if ($blocker !== null) {
                $db->table('engage_payout_runs')->where('id', $runId)->update(['status' => 'failed', 'error' => $blocker, 'finished_at' => Clock::now()]);
                $db->transCommit();
                log_message('error', "Payout run {$w->code()} not built: {$blocker}");

                return ['status' => 503, 'code' => 'finance_unavailable', 'error' => "Payout run {$w->code()} not built: {$blocker}", 'data' => ['run' => $this->runView($this->findRun($runId))]];
            }

            $groups = $this->collect($w->endsAt(), $min, true);
            $count = ['payouts' => 0, 'entries' => 0, 'gross' => 0, 'below' => 0, 'below_amt' => 0, 'inel' => 0, 'inel_amt' => 0];
            foreach ($groups as $g) {
                if (! $g['included']) {
                    $k = $g['skip_reason'] === 'below_minimum' ? 'below' : 'inel';
                    $count[$k]++;
                    $count[$k . '_amt'] += $g['paise'];

                    continue;
                }
                $ref = $w->payoutRef((string) $g['seller_code']);
                $db->table('engage_seller_payouts')->insert([
                    'payout_ref' => $ref, 'item_ref' => $ref, 'run_id' => $runId, 'batch_ref' => $w->batchRef(),
                    'seller_id' => $g['seller_id'], 'seller_code' => $g['seller_code'], 'iso_week' => $w->code(),
                    'period_start' => $w->periodStart(), 'period_end' => $w->periodEnd(), 'gross_amount' => Money::format($g['paise']),
                    'entries_count' => count($g['entry_ids']), 'entry_ids' => json_encode($g['entry_ids']), 'status' => 'queued',
                ]);
                $payoutId = (int) $db->insertID();
                $moved = $db->query(
                    "UPDATE engage_commission_entries SET status = 'in_payout', payout_item_ref = ?, updated_at = NOW()
                      WHERE id IN (" . implode(',', array_map('intval', $g['entry_ids'])) . ") AND status = 'approved' AND payout_item_ref IS NULL RETURNING id",
                    [$ref]
                )->getResultArray();
                if (count($moved) !== count($g['entry_ids'])) {
                    throw new \RuntimeException("Commission entries of {$g['seller_code']} changed while the run was building.");
                }
                $this->event($payoutId, 'engage', null, 'queued', ['run' => $w->code(), 'entries' => $g['entry_ids']]);
                $count['payouts']++;
                $count['entries'] += count($g['entry_ids']);
                $count['gross'] += $g['paise'];
            }
            $status = $count['payouts'] > 0 ? 'queued' : 'empty';
            if ($status === 'queued') {
                $this->outbox()->enqueue($runId, $w->batchRef());
            }
            $db->table('engage_payout_runs')->where('id', $runId)->update([
                'status' => $status, 'sellers_considered' => count($groups), 'payouts_count' => $count['payouts'], 'entries_count' => $count['entries'],
                'gross_amount' => Money::format($count['gross']), 'below_minimum_count' => $count['below'], 'below_minimum_amount' => Money::format($count['below_amt']),
                'ineligible_count' => $count['inel'], 'ineligible_amount' => Money::format($count['inel_amt']), 'finished_at' => Clock::now(), 'updated_at' => Clock::now(),
            ]);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            $this->recordFailure($w, $trigger, $staffId, $e->getMessage());
            log_message('error', "Payout run {$w->code()} failed: " . $e->getMessage());

            return ['status' => 500, 'error' => "Payout run {$w->code()} failed: " . $e->getMessage()];
        }

        Services::auditService()->log('payout.run_built', [
            'subject_kind' => 'payout_run', 'subject_id' => $runId, 'actor_id' => $staffId,
            'metadata' => ['week' => $w->code(), 'payouts' => $count['payouts'], 'gross' => Money::format($count['gross']), 'trigger' => $trigger], 'fanout_console' => true,
        ]);
        $delivery = null;
        if ($status === 'queued' && $deliver) {
            $delivery = $this->outbox()->deliverRun($runId);
        }

        return ['status' => 201, 'data' => ['result' => 'created', 'delivery' => $delivery, 'run' => $this->runView($this->findRun($runId))]];
    }

    private function recordFailure(PayoutWeek $w, string $trigger, ?int $staffId, string $error): void
    {
        try {
            $this->db()->query(
                "INSERT INTO engage_payout_runs (iso_week, batch_ref, period_start, period_end, cutoff_at, status, trigger_source, triggered_by, min_payout, error, finished_at)
                 VALUES (?, ?, ?, ?, ?, 'failed', ?, ?, ?, ?, NOW())
                 ON CONFLICT (iso_week) DO UPDATE SET status = 'failed', error = EXCLUDED.error, finished_at = NOW(), updated_at = NOW()
                 WHERE engage_payout_runs.status IN ('failed','running')",
                [$w->code(), $w->batchRef(), $w->periodStart(), $w->periodEnd(), Clock::at($w->endsAt()), $trigger === 'manual' ? 'manual' : 'schedule', $staffId,
                    Money::format(self::minPayoutPaise()), substr($error, 0, 2000)]
            );
        } catch (\Throwable $e) {
            log_message('critical', 'Could not record failed payout run ' . $w->code() . ': ' . $e->getMessage());
        }
    }

    /** "Re-send to Finance" for a batch whose delivery was rejected / gave up (or is still retrying). */
    public function resend(int $runId, array $staff): array
    {
        $run = $this->findRun($runId);
        if (! $run) {
            return ['status' => 404, 'error' => 'Payout run not found.'];
        }
        $row = $this->outbox()->forRun($runId);
        if (! $row || ! in_array($row['status'], ['pending', 'rejected', 'dead'], true)) {
            return ['status' => 409, 'error' => $row ? 'This batch is ' . $row['status'] . '; nothing to re-send.' : 'This run has no batch for Finance.'];
        }
        if (! $this->outbox()->isConfigured()) {
            return ['status' => 503, 'error' => $this->outbox()->status()];
        }
        $this->outbox()->resend($runId);
        $this->db()->query("UPDATE engage_payout_runs SET status = 'queued', updated_at = NOW() WHERE id = ? AND status = 'delivery_failed'", [$runId]);
        $result = $this->outbox()->deliverRun($runId);
        Services::auditService()->log('payout.batch_resent', [
            'subject_kind' => 'payout_run', 'subject_id' => $runId, 'metadata' => ['batch_ref' => $run['batch_ref'], 'result' => $result], 'fanout_console' => true,
        ]);

        return ['status' => 200, 'data' => ['delivery' => $result, 'run' => $this->runView($this->findRun($runId))]];
    }

    /** Cancel a batch Finance never received (rejected / dead / still pending): payouts cancelled, entries back to approved. */
    public function cancelRun(int $runId, array $staff, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Give a reason.']];
        }
        $db = $this->db();
        $run = $this->findRun($runId);
        if (! $run) {
            return ['status' => 404, 'error' => 'Payout run not found.'];
        }
        $row = $this->outbox()->forRun($runId);
        if (! $row || ! in_array($row['status'], ['pending', 'rejected', 'dead'], true)) {
            return ['status' => 409, 'error' => $row && $row['status'] === 'delivered'
                ? 'Finance has this batch; it must be cancelled in Finance (its status comes back here).' : 'Nothing to cancel.'];
        }
        $db->transStart();
        $db->query("UPDATE engage_finance_payout_outbox SET status = 'cancelled', last_error = ? WHERE id = ?", ['cancelled: ' . $reason, $row['id']]);
        $returned = 0;
        foreach ($db->table('engage_seller_payouts')->where('run_id', $runId)->whereIn('status', ['queued'])->get()->getResultArray() as $p) {
            $returned += $this->returnEntries($p);
            $db->table('engage_seller_payouts')->where('id', (int) $p['id'])->update(['status' => 'cancelled', 'reason' => $reason, 'updated_at' => Clock::now()]);
            $this->event((int) $p['id'], 'engage', $p['status'], 'cancelled', ['reason' => $reason, 'by' => (int) $staff['id']]);
        }
        $db->table('engage_payout_runs')->where('id', $runId)->update(['status' => 'cancelled', 'cancelled_at' => Clock::now(), 'cancelled_by' => (int) $staff['id'],
            'error' => 'Cancelled before Finance received it: ' . $reason, 'updated_at' => Clock::now()]);
        $db->transComplete();
        Services::auditService()->log('payout.run_cancelled', [
            'subject_kind' => 'payout_run', 'subject_id' => $runId, 'metadata' => ['batch_ref' => $run['batch_ref'], 'reason' => $reason, 'entries_returned' => $returned], 'fanout_console' => true,
        ]);

        return ['status' => 200, 'data' => ['entries_returned' => $returned, 'run' => $this->runView($this->findRun($runId))]];
    }

    /** Entries of a payout leave it and become approved again (they roll into a later run). */
    private function returnEntries(array $payout): int
    {
        return count($this->db()->query(
            "UPDATE engage_commission_entries SET status = 'approved', payout_item_ref = NULL, paid_at = NULL, updated_at = NOW()
              WHERE payout_item_ref = ? AND status IN ('in_payout','paid') RETURNING id",
            [$payout['payout_ref']]
        )->getResultArray());
    }

    private function event(int $payoutId, string $source, ?string $from, string $to, array $payload, ?string $sha = null): void
    {
        $this->db()->query('INSERT INTO engage_seller_payout_events (payout_id, source, from_status, to_status, payload, payload_sha256) VALUES (?, ?, ?, ?, CAST(? AS JSONB), ?)',
            [$payoutId, $source, $from, $to, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $sha]);
    }

    // ------------------------------------------------------------------ Finance status callback

    /**
     * SellerPayoutGateway: POST v1/inbound/finance/payout-status
     * {batch_ref, item_ref, status, tds_amount, gst_amount, net_amount, utr|null, paid_at|null, reason|null, tds_section?}
     *
     * @return array{status:int, data?:array, error?:string, code?:string, details?:array}
     */
    public function applyFinanceStatus(array $in): array
    {
        $e = [];
        $str = static fn ($v): string => is_scalar($v) ? trim((string) $v) : '';
        $batchRef = $str($in['batch_ref'] ?? '');
        $itemRef  = $str($in['item_ref'] ?? '');
        $status   = strtolower($str($in['status'] ?? ''));
        if (strlen($itemRef) > 32 || strlen($batchRef) > 24) {
            $e['item_ref'] = 'Unknown reference format.';
        }
        if ($batchRef === '') {
            $e['batch_ref'] = 'batch_ref is required.';
        }
        if ($itemRef === '') {
            $e['item_ref'] = 'item_ref is required.';
        }
        if (! in_array($status, self::FINANCE_STATUSES, true)) {
            $e['status'] = 'status must be one of ' . implode(', ', self::FINANCE_STATUSES) . '.';
        }
        $amounts = [];
        foreach (['tds_amount', 'gst_amount', 'net_amount'] as $f) {
            $v = $in[$f] ?? null;
            if ($v === null || $v === '') {
                $amounts[$f] = null;

                continue;
            }
            try {
                if (! is_scalar($v) || is_bool($v)) {
                    throw new InvalidArgumentException('not an amount');
                }
                $amounts[$f] = Money::format(Money::toPaise(is_int($v) ? $v : (string) $v));
            } catch (InvalidArgumentException $ex) {
                $e[$f] = "{$f} must be an amount like \"1234.50\".";
            }
        }
        $utr = $str($in['utr'] ?? '');
        if ($utr !== '' && ! preg_match('/\A[A-Za-z0-9\-\/]{4,64}\z/', $utr)) {
            $e['utr'] = 'utr: 4–64 letters, digits, - or /.';
        }
        if ($status === 'paid' && $utr === '') {
            $e['utr'] = 'A paid status needs the UTR / bank reference.';
        }
        $paidAt = null;
        if (! empty($in['paid_at'])) {
            $t = is_string($in['paid_at']) ? strtotime($in['paid_at']) : false;
            if ($t === false || $t > time() + 600) {
                $e['paid_at'] = 'paid_at must be an ISO 8601 time that is not in the future.';
            } else {
                $paidAt = $t;
            }
        }
        $reason = $str($in['reason'] ?? '') !== '' ? mb_substr($str($in['reason']), 0, 1000) : null;
        $tdsSection = isset($in['tds_section']) && is_string($in['tds_section']) && preg_match('/\A[0-9A-Za-z()]{2,16}\z/', $in['tds_section']) ? $in['tds_section'] : null;
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Invalid payout status.', 'details' => $e];
        }

        $db = $this->db();
        $db->transBegin();
        try {
            $p = $db->query('SELECT * FROM engage_seller_payouts WHERE item_ref = ? FOR UPDATE', [$itemRef])->getRowArray();
            if (! $p) {
                $db->transRollback();

                return ['status' => 404, 'code' => 'not_found', 'error' => "Unknown item_ref {$itemRef}."];
            }
            if ($p['batch_ref'] !== $batchRef) {
                $db->transRollback();

                return ['status' => 422, 'code' => 'validation_failed', 'error' => "item_ref {$itemRef} belongs to batch {$p['batch_ref']}, not {$batchRef}.", 'details' => ['batch_ref' => 'Does not match the item.']];
            }
            $canon = ['status' => $status, 'utr' => $utr !== '' ? $utr : null, 'paid_at' => $paidAt !== null ? gmdate('c', $paidAt) : null, 'reason' => $reason, 'tds_section' => $tdsSection] + $amounts;
            $sha = hash('sha256', (string) json_encode($canon));
            // A repeat of any status Finance already reported (retries, late duplicates) changes nothing.
            $seen = $db->query("SELECT 1 FROM engage_seller_payout_events WHERE payout_id = ? AND source = 'finance' AND payload_sha256 = ? LIMIT 1", [(int) $p['id'], $sha])->getRow();
            if ($seen) {
                $db->transRollback();

                return ['status' => 200, 'data' => ['item_ref' => $itemRef, 'status' => $p['status'], 'result' => 'unchanged']];
            }
            $from = (string) $p['status'];
            if (! in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
                $db->transRollback();
                $why = $from === 'paid' && $status === 'paid' ? "already paid with UTR {$p['utr']}" : "is {$from}";

                return ['status' => 409, 'code' => 'conflict', 'error' => "Payout {$itemRef} {$why}; status {$status} cannot be applied."];
            }
            $upd = ['status' => $status, 'finance_status_at' => Clock::now(), 'updated_at' => Clock::now()];
            foreach ($amounts as $f => $v) {
                if ($v !== null) {
                    $upd[$f] = $v;
                }
            }
            if ($tdsSection !== null) {
                $upd['tds_section'] = $tdsSection;
            }
            if ($reason !== null || in_array($status, ['approved', 'paid'], true)) {
                $upd['reason'] = $reason;
            }
            $paidEntries = 0;
            $returned = 0;
            if ($status === 'paid') {
                $upd['utr'] = $utr;
                $upd['paid_at'] = Clock::at($paidAt ?? time());
                $paidEntries = count($db->query(
                    "UPDATE engage_commission_entries SET status = 'paid', paid_at = ?, updated_at = NOW() WHERE payout_item_ref = ? AND status = 'in_payout' RETURNING id",
                    [$upd['paid_at'], $p['payout_ref']]
                )->getResultArray());
            } elseif (in_array($status, self::RETURNING, true)) {
                $returned = $this->returnEntries($p);
            }
            $db->table('engage_seller_payouts')->where('id', (int) $p['id'])->update($upd);
            $this->event((int) $p['id'], 'finance', $from, $status, $canon + ['batch_ref' => $batchRef, 'item_ref' => $itemRef], $sha);
            // Finance has the batch even if its 201 never reached us.
            if ($from === 'queued') {
                $db->query("UPDATE engage_finance_payout_outbox SET status = 'delivered', delivered_at = COALESCE(delivered_at, NOW()), last_error = NULL
                             WHERE run_id = ? AND status IN ('pending','rejected','dead')", [(int) $p['run_id']]);
                $db->query("UPDATE engage_seller_payouts SET status = 'draft_sent', sent_at = COALESCE(sent_at, NOW()) WHERE run_id = ? AND status = 'queued'", [(int) $p['run_id']]);
                $db->query("UPDATE engage_payout_runs SET status = 'sent', sent_at = COALESCE(sent_at, NOW()), error = NULL WHERE id = ? AND status IN ('queued','delivery_failed')", [(int) $p['run_id']]);
            }
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();
            log_message('error', "Finance payout status for {$itemRef} failed: " . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not apply the payout status.'];
        }
        Services::auditService()->log('payout.finance_status', [
            'subject_kind' => 'seller_payout', 'subject_id' => (int) $p['id'], 'actor_email' => 'finance.aicountly.org',
            'metadata' => ['item_ref' => $itemRef, 'from' => $from, 'to' => $status, 'utr' => $utr ?: null, 'entries_paid' => $paidEntries, 'entries_returned' => $returned],
            'fanout_console' => in_array($status, ['paid', 'failed', 'reversed', 'cancelled'], true),
        ]);

        return ['status' => 200, 'data' => ['item_ref' => $itemRef, 'status' => $status, 'result' => 'updated', 'entries_paid' => $paidEntries, 'entries_returned' => $returned]];
    }

    // ------------------------------------------------------------------ staff views

    /** @return array<string,mixed>|null */
    public function findRun(int $id): ?array
    {
        return $this->db()->table('engage_payout_runs')->where('id', $id)->get()->getRowArray() ?: null;
    }

    public function runView(array $r): array
    {
        $box = $this->outbox()->forRun((int) $r['id']);
        $money = static fn ($v): string => Money::format(Money::toPaise((string) ($v ?? '0')));

        return [
            'id'                   => (int) $r['id'],
            'week'                 => $r['iso_week'],
            'batch_ref'            => $r['batch_ref'],
            'period_start'         => $r['period_start'],
            'period_end'           => $r['period_end'],
            'cutoff_at'            => Clock::iso($r['cutoff_at']),
            'status'               => $r['status'],
            'trigger'              => $r['trigger_source'],
            'triggered_by'         => $r['triggered_by'] !== null ? (int) $r['triggered_by'] : null,
            'min_payout'           => $money($r['min_payout']),
            'sellers_considered'   => (int) $r['sellers_considered'],
            'payouts_count'        => (int) $r['payouts_count'],
            'entries_count'        => (int) $r['entries_count'],
            'gross_amount'         => $money($r['gross_amount']),
            'below_minimum_count'  => (int) $r['below_minimum_count'],
            'below_minimum_amount' => $money($r['below_minimum_amount']),
            'ineligible_count'     => (int) $r['ineligible_count'],
            'ineligible_amount'    => $money($r['ineligible_amount']),
            'finance_batch_id'     => $r['finance_batch_id'],
            'error'                => $r['error'],
            'attempts'             => (int) $r['attempts'],
            'started_at'           => Clock::iso($r['started_at']),
            'finished_at'          => Clock::iso($r['finished_at']),
            'sent_at'              => Clock::iso($r['sent_at']),
            'cancelled_at'         => Clock::iso($r['cancelled_at']),
            'delivery'             => $box ? [
                'status' => $box['status'], 'attempts' => (int) $box['attempts'], 'last_http_status' => $box['last_http_status'] !== null ? (int) $box['last_http_status'] : null,
                'last_error' => $box['last_error'], 'next_attempt_at' => Clock::iso($box['next_attempt_at']), 'delivered_at' => Clock::iso($box['delivered_at']),
            ] : null,
            'can_resend'           => $box !== null && in_array($box['status'], ['rejected', 'dead', 'pending'], true),
        ];
    }

    /** @return array<string,mixed> */
    public function listRuns(int $limit, int $offset): array
    {
        $b = $this->db()->table('engage_payout_runs');
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('period_start', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return [
            'items'    => array_map(fn (array $r): array => $this->runView($r), $rows),
            'total'    => $total,
            'finance'  => ['configured' => $this->outbox()->isConfigured(), 'status' => $this->outbox()->status(), 'outbox' => $this->outbox()->summary(),
                'inbound_key' => strlen(trim((string) env('ENGAGE_INBOUND_FINANCE_KEY', ''))) >= 32 ? 'ok' : 'ENGAGE_INBOUND_FINANCE_KEY missing or shorter than 32 characters — Finance status updates are refused (503)'],
            'schedule' => ['next_run_date' => PayoutWeek::nextRunDate(), 'next_run_week' => PayoutWeek::current()->code(), 'at' => 'Monday 10:00 IST',
                'last_completed_week' => PayoutWeek::lastCompleted()->code()],
            'min_payout' => Money::format(self::minPayoutPaise()),
        ];
    }

    public static function payoutView(array $p): array
    {
        $m = static fn ($v): ?string => $v === null ? null : Money::format(Money::toPaise((string) $v));

        return [
            'id'                => (int) $p['id'],
            'payout_ref'        => $p['payout_ref'],
            'item_ref'          => $p['item_ref'],
            'run_id'            => (int) $p['run_id'],
            'batch_ref'         => $p['batch_ref'],
            'seller_id'         => (int) $p['seller_id'],
            'seller_code'       => $p['seller_code'],
            'seller_name'       => $p['seller_name'] ?? null,
            'week'              => $p['iso_week'],
            'period_start'      => $p['period_start'],
            'period_end'        => $p['period_end'],
            'currency'          => $p['currency'],
            'gross_amount'      => $m($p['gross_amount']),
            'tds_section'       => $p['tds_section'],
            'tds_amount'        => $m($p['tds_amount']),
            'gst_amount'        => $m($p['gst_amount']),
            'net_amount'        => $m($p['net_amount']),
            'utr'               => $p['utr'],
            'paid_at'           => Clock::iso($p['paid_at']),
            'status'            => $p['status'],
            'reason'            => $p['reason'],
            'entries_count'     => (int) $p['entries_count'],
            'entry_ids'         => array_map('intval', json_decode((string) $p['entry_ids'], true) ?: []),
            'finance_batch_ref' => $p['finance_batch_ref'],
            'sent_at'           => Clock::iso($p['sent_at']),
            'finance_status_at' => Clock::iso($p['finance_status_at']),
            'created_at'        => Clock::iso($p['created_at']),
        ];
    }

    private function payoutsQuery()
    {
        return $this->db()->table('engage_seller_payouts p')->select('p.*, s.name AS seller_name')->join('engage_sellers s', 's.id = p.seller_id');
    }

    /** @return array<string,mixed> */
    public function listPayouts(array $q, int $limit, int $offset): array
    {
        $b = $this->payoutsQuery();
        foreach (['seller_id' => 'p.seller_id', 'run_id' => 'p.run_id'] as $k => $col) {
            if (! empty($q[$k])) {
                $b->where($col, (int) $q[$k]);
            }
        }
        if (! empty($q['status'])) {
            $b->where('p.status', (string) $q['status']);
        }
        if (! empty($q['week'])) {
            $b->where('p.iso_week', (string) $q['week']);
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('p.period_start', 'DESC')->orderBy('p.seller_code')->limit($limit, $offset)->get()->getResultArray();

        return ['items' => array_map([self::class, 'payoutView'], $rows), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function staffPayout(int $id): ?array
    {
        $p = $this->payoutsQuery()->where('p.id', $id)->get()->getRowArray();
        if (! $p) {
            return null;
        }
        $events = $this->db()->table('engage_seller_payout_events')->where('payout_id', $id)->orderBy('id')->get()->getResultArray();

        return self::payoutView($p) + [
            'entries' => $this->entryLines($p),
            'events'  => array_map(static fn (array $ev): array => [
                'source' => $ev['source'], 'from' => $ev['from_status'], 'to' => $ev['to_status'],
                'payload' => json_decode((string) $ev['payload'], true), 'at' => Clock::iso($ev['created_at']),
            ], $events),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function entryLines(array $p): array
    {
        $ids = array_map('intval', json_decode((string) $p['entry_ids'], true) ?: []);
        if ($ids === []) {
            return [];
        }
        $rows = $this->db()->query(
            'SELECT e.id, e.amount, e.accrued_at, e.status, e.payout_item_ref, e.product_code, l.lead_code, pr.name AS product_name
               FROM engage_commission_entries e JOIN engage_leads l ON l.id = e.lead_id LEFT JOIN engage_products pr ON pr.code = e.product_code
              WHERE e.id IN (' . implode(',', $ids) . ') ORDER BY e.id'
        )->getResultArray();

        return array_map(static fn (array $e): array => [
            'id' => 'CE-' . $e['id'], 'entry_id' => (int) $e['id'], 'lead_code' => $e['lead_code'], 'product' => $e['product_name'] ?? $e['product_code'],
            'amount' => Money::format(Money::toPaise((string) $e['amount'])), 'accrued_at' => Clock::iso($e['accrued_at']),
            'status' => $e['status'], 'in_this_payout' => $e['payout_item_ref'] === $p['payout_ref'],
        ], $rows);
    }

    // ------------------------------------------------------------------ seller portal

    /** Seller-facing status (seller-aicountly ENGAGE_SELLER_API.md): queued / draft_sent are "processing". */
    public static function sellerStatus(string $status): string
    {
        return in_array($status, ['queued', 'draft_sent'], true) ? 'processing' : $status;
    }

    private static function sellerStatusFilter(string $status): array
    {
        return $status === 'processing' ? ['queued', 'draft_sent'] : [$status];
    }

    public static function sellerItem(array $p): array
    {
        $m = static fn ($v): ?string => $v === null ? null : Money::format(Money::toPaise((string) $v));
        $status = self::sellerStatus((string) $p['status']);

        return [
            'payout_ref'    => $p['payout_ref'],
            'batch_ref'     => $p['batch_ref'],
            'period_start'  => $p['period_start'],
            'period_end'    => $p['period_end'],
            'gross_amount'  => $m($p['gross_amount']),
            'tds_amount'    => $m($p['tds_amount']),
            'gst_amount'    => $m($p['gst_amount']),
            'net_amount'    => $m($p['net_amount']),
            'utr'           => $status === 'paid' || $status === 'reversed' ? $p['utr'] : null,
            'status'        => $status,
            'paid_at'       => $status === 'paid' || $status === 'reversed' ? Clock::iso($p['paid_at']) : null,
            'reason'        => $p['reason'],
            'entries_count' => (int) $p['entries_count'],
        ];
    }

    /** GET seller-portal payouts. @return array<string,mixed> */
    public function sellerPayouts(int $sellerId, int $limit, int $offset, ?string $status = null): array
    {
        $b = $this->db()->table('engage_seller_payouts')->where('seller_id', $sellerId);
        if ($status !== null && $status !== '') {
            $b->whereIn('status', self::sellerStatusFilter($status));
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('period_start', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['items' => array_map([self::class, 'sellerItem'], $rows), 'total' => $total];
    }

    /** GET seller-portal payouts/{ref} — null when it is not this seller's. @return array<string,mixed>|null */
    public function sellerPayout(int $sellerId, string $ref): ?array
    {
        $p = $this->db()->table('engage_seller_payouts')->where('payout_ref', $ref)->where('seller_id', $sellerId)->get()->getRowArray();
        if (! $p) {
            return null;
        }
        $s = $this->db()->table('engage_sellers')->where('id', $sellerId)->get()->getRowArray();
        $k = $this->db()->table('engage_seller_kyc')->where('seller_id', $sellerId)->get()->getRowArray() ?: [];
        $entries = array_map(static fn (array $e): array => ['id' => $e['id'], 'lead_code' => $e['lead_code'], 'product' => $e['product'], 'amount' => $e['amount'], 'accrued_at' => $e['accrued_at']],
            $this->entryLines($p));
        $issuer = self::issuer();

        return self::sellerItem($p) + [
            'currency'     => 'INR',
            'tds_section'  => $p['tds_section'] ?? '194H',
            'entries'      => $entries,
            'payee'        => [
                'legal_name'   => $k['legal_name'] ?? ($s['name'] ?? null),
                'seller_code'  => $p['seller_code'],
                'entity_type'  => $k['entity_type'] ?? ($s['entity_type'] ?? null),
                'pan_masked'   => ! empty($k['pan_last4']) ? 'XXXXXX' . $k['pan_last4'] : null,
                'gstin_masked' => ! empty($k['gstin_last4']) ? ($k['gst_state_code'] ?? 'XX') . 'XXXXXXXXX' . $k['gstin_last4'] : null,
            ],
            'issuer'       => $issuer,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** Statement issuer (PAYOUT_ISSUER_NAME / _ADDRESS / _GSTIN); null when not configured. */
    public static function issuer(): ?array
    {
        $name = trim((string) env('PAYOUT_ISSUER_NAME', ''));
        if ($name === '') {
            return null;
        }

        return ['name' => $name, 'address' => trim((string) env('PAYOUT_ISSUER_ADDRESS', '')) ?: null, 'gstin' => trim((string) env('PAYOUT_ISSUER_GSTIN', '')) ?: null];
    }

    /** The printable statement (text/html, self-contained, no scripts). */
    public static function statementHtml(array $p): string
    {
        $h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amt = static fn (?string $v): string => $v === null ? 'Pending' : '₹' . $h($v);
        $date = static fn (?string $d): string => $d ? $h(SellerLeadService::istDate($d, 'j M Y')) : '—';
        $statusLabel = [
            'processing' => 'Processing (draft with Finance)', 'approved' => 'Approved by Finance', 'on_hold' => 'On hold', 'paid' => 'Paid',
            'failed' => 'Failed — amount returned to your balance', 'reversed' => 'Reversed — amount returned to your balance', 'cancelled' => 'Cancelled — amount returned to your balance',
        ][$p['status']] ?? $p['status'];
        $rows = '';
        foreach ($p['entries'] as $e) {
            $rows .= '<tr><td>' . $h($e['id']) . '</td><td>' . $h($e['lead_code']) . '</td><td>' . $h($e['product']) . '</td><td>' . $date($e['accrued_at'])
                . '</td><td class="r">₹' . $h($e['amount']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">No entries.</td></tr>';
        }
        $issuer = $p['issuer'] ? '<p class="muted">' . $h($p['issuer']['name']) . ($p['issuer']['address'] ? '<br>' . nl2br($h($p['issuer']['address'])) : '')
            . ($p['issuer']['gstin'] ? '<br>GSTIN ' . $h($p['issuer']['gstin']) : '') . '</p>' : '';
        $payee = $p['payee'];

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Payout statement ' . $h($p['payout_ref']) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2937;max-width:760px;margin:24px auto;padding:0 16px;font-size:14px}'
            . 'h1{font-size:20px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin:12px 0}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #e5e7eb}'
            . '.r{text-align:right}.muted{color:#6b7280}.tot td{font-weight:600}.box{border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin:12px 0}</style></head><body>'
            . '<h1>AICOUNTLY seller payout statement</h1>' . $issuer
            . '<div class="box"><table>'
            . '<tr><th>Payout</th><td>' . $h($p['payout_ref']) . '</td><th>Batch</th><td>' . $h($p['batch_ref']) . '</td></tr>'
            . '<tr><th>Period</th><td>' . $date($p['period_start']) . ' – ' . $date($p['period_end']) . '</td><th>Status</th><td>' . $h($statusLabel) . '</td></tr>'
            . '<tr><th>Seller</th><td>' . $h($payee['legal_name']) . ' (' . $h($payee['seller_code']) . ')</td><th>Entity</th><td>' . $h($payee['entity_type']) . '</td></tr>'
            . '<tr><th>PAN</th><td>' . $h($payee['pan_masked'] ?? '—') . '</td><th>GSTIN</th><td>' . $h($payee['gstin_masked'] ?? 'Not registered') . '</td></tr>'
            . '</table></div>'
            . '<h2 style="font-size:16px">Commission entries</h2><table><thead><tr><th>Entry</th><th>Lead</th><th>Product</th><th>Accrued</th><th class="r">Amount</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<table><tbody>'
            . '<tr><td>Gross commission</td><td class="r">' . $amt($p['gross_amount']) . '</td></tr>'
            . '<tr><td>TDS (section ' . $h($p['tds_section']) . ')</td><td class="r">' . ($p['tds_amount'] === null ? 'Pending' : '− ₹' . $h($p['tds_amount'])) . '</td></tr>'
            . '<tr><td>GST</td><td class="r">' . ($p['gst_amount'] === null ? 'Pending' : '+ ₹' . $h($p['gst_amount'])) . '</td></tr>'
            . '<tr class="tot"><td>Net payable</td><td class="r">' . $amt($p['net_amount']) . '</td></tr>'
            . '<tr><td>UTR / bank reference</td><td class="r">' . $h($p['utr'] ?? '—') . '</td></tr>'
            . '<tr><td>Paid on</td><td class="r">' . $date($p['paid_at']) . '</td></tr>'
            . ($p['reason'] ? '<tr><td>Note</td><td class="r">' . $h($p['reason']) . '</td></tr>' : '')
            . '</tbody></table>'
            . '<p class="muted">TDS, GST and net amounts are computed by AICOUNTLY Finance and shown as "Pending" until Finance confirms them. '
            . 'This statement is not a TDS certificate (Form 16A). Generated ' . $h(gmdate('j M Y H:i')) . ' UTC.</p>'
            . '</body></html>';
    }

    /** Dashboard: next Monday run date for a seller who can be paid, else null. */
    public static function nextPayoutDate(array $seller): ?string
    {
        return ($seller['kyc_status'] ?? '') === 'kyc_verified' && ($seller['status'] ?? '') === 'active' ? PayoutWeek::nextRunDate() : null;
    }
}

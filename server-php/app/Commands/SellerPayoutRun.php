<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Libraries\Seller\Money;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:seller-payout-run [--week=YYYY-Www] [--dry-run]` — build the weekly seller payout run and send
 * the DRAFT batch to finance.aicountly.org (docs/SELLER_PROGRAM.md § 6). Default week: the last one that ended
 * (Monday 00:00 – Sunday 23:59 IST). Idempotent per ISO week: a week that already has a run is reported, not rebuilt.
 * --dry-run prints what the run would include and writes nothing (default week then: the current one).
 *
 * Monday 10:00 IST (04:30 UTC):
 *   30 4 * * 1 cd /path/to/api && php spark engage:seller-payout-run >> writable/logs/seller_payout_run.log 2>&1
 * (or with CRON_TZ=Asia/Kolkata: 0 10 * * 1). Reports to Console's Cron Job Monitor as engage.seller_payout_run.
 */
class SellerPayoutRun extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:seller-payout-run';
    protected $description = 'Build the weekly seller payout run and send it to Finance as a draft batch.';
    protected $usage       = 'engage:seller-payout-run [--week=YYYY-Www] [--dry-run]';
    protected $options     = ['--week' => 'ISO week, e.g. 2026-W42 (default: the last week that ended)', '--dry-run' => 'Show what would be included; write nothing'];

    public function run(array $params)
    {
        [$week, $dry] = self::options($params, $_SERVER['argv'] ?? []);
        $svc = Services::sellerPayouts();

        if ($dry) {
            try {
                $p = $svc->preview($week);
            } catch (\InvalidArgumentException $e) {
                CLI::error($e->getMessage());

                return EXIT_ERROR;
            }
            CLI::write("Dry run for {$p['week']} ({$p['period_start']} – {$p['period_end']}), cutoff {$p['cutoff_at']}, minimum ₹{$p['min_payout']}:");
            foreach ($p['sellers'] as $s) {
                CLI::write(sprintf('  %-12s ₹%12s  %3d entr%s  %s', $s['seller_code'], $s['gross_amount'], $s['entries_count'], $s['entries_count'] === 1 ? 'y ' : 'ies',
                    $s['included'] ? 'included' : 'skipped: ' . $s['skip_label']));
            }
            CLI::write("Would send {$p['payouts_count']} payout(s), ₹{$p['gross_amount']} gross; ₹{$p['held_amount']} held / rolled over. Finance: {$p['finance']}"
                . ($p['existing_run'] ? " (a run for this week already exists: {$p['existing_run']['status']})" : ''));

            return EXIT_SUCCESS;
        }

        try {
            $r = $svc->run($week, 'schedule');
        } catch (\Throwable $e) {
            $r = ['status' => 500, 'error' => $e->getMessage()];
        }
        $run = $r['data']['run'] ?? null;
        if ($r['status'] >= 400) {
            CLI::error((string) ($r['error'] ?? 'Payout run failed.'));
            CronHeartbeat::send('engage.seller_payout_run', 'error', substr((string) ($r['error'] ?? ''), 0, 500));

            return EXIT_ERROR;
        }
        if (($r['data']['result'] ?? '') === 'exists') {
            CLI::write("Payout run {$run['week']} already exists ({$run['status']}); nothing done.");
            CronHeartbeat::send('engage.seller_payout_run', 'ok', 'already exists', ['payouts' => $run['payouts_count']]);

            return EXIT_SUCCESS;
        }
        CLI::write(sprintf('Payout run %s: %s — %d payout(s), %d entries, ₹%s gross; %d seller(s) below the minimum (₹%s rolls over), %d held (KYC / status). Delivery: %s.',
            $run['week'], $run['status'], $run['payouts_count'], $run['entries_count'], $run['gross_amount'], $run['below_minimum_count'], $run['below_minimum_amount'],
            $run['ineligible_count'], $r['data']['delivery'] ?? 'n/a'));
        CronHeartbeat::send('engage.seller_payout_run', 'ok', '', ['payouts' => $run['payouts_count'], 'entries' => $run['entries_count'],
            'gross_paise' => Money::toPaise($run['gross_amount'])]);

        return EXIT_SUCCESS;
    }

    /**
     * CodeIgniter's CLI parser keeps "--week=2026-W42" as an option named "week=2026-W42"; accept both spellings.
     *
     * @return array{0:?string, 1:bool}
     */
    public static function options(array $params, array $argv): array
    {
        $week = null;
        $dry = array_key_exists('dry-run', $params) || in_array('--dry-run', $argv, true);
        foreach ($params as $k => $v) {
            if ($k === 'week' && is_string($v) && $v !== '') {
                $week = $v;
            } elseif (is_string($k) && str_starts_with($k, 'week=')) {
                $week = substr($k, 5);
            }
        }
        foreach ($argv as $i => $a) {
            if (str_starts_with($a, '--week=')) {
                $week = substr($a, 7);
            } elseif ($a === '--week' && isset($argv[$i + 1])) {
                $week = $argv[$i + 1];
            }
        }

        return [$week !== null && trim($week) !== '' ? trim($week) : null, $dry];
    }
}

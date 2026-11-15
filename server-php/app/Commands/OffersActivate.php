<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Services\Licensing\EntitlementOutbox;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;

/**
 * `php spark engage:offers-activate` — record the price revisions whose effective date has come
 * (docs/SELLING_LICENCES.md §4): each scheduled offer version becomes published from its effective date and the version
 * it replaces is retired at that instant. Reads never wait for this — a revision is on sale from its effective date
 * whether or not this has run — so it keeps the stored statuses honest and does the one thing reads cannot: when the
 * revision is a product's Basic (default) offer, every Basic holder of that product is re-projected and the changed
 * entitlement states are queued for Manage (what `engage:entitlements-reproject --product=` did by hand).
 *
 * Scheduled every minute by bin/ops/jobs.txt (offers-activate); reports to Console's Cron Job Monitor as
 * engage.offers_activate (inert without CONSOLE_CRON_MONITOR_KEY). Exits non-zero when a revision could not be recorded.
 */
class OffersActivate extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:offers-activate';
    protected $description = 'Record offer price revisions whose effective date has come (scheduled → published).';
    protected $usage       = 'engage:offers-activate';

    public function run(array $params)
    {
        try {
            $r = Services::offers()->activateDue();
            $queued = 0;
            foreach ($r['activated'] as $a) {
                CLI::write("{$a['offer_code']} v{$a['version']} in effect from {$a['effective_from']}"
                    . ($a['replaced_version'] !== null ? " (replaced v{$a['replaced_version']})" : '') . '.');
                if ($a['is_default']) {
                    // A new Basic version: Basic holders are pushed with the current Basic limits.
                    $p = EntitlementOutbox::reproject(Database::connect(), null, $a['product_code']);
                    $queued += $p['queued'];
                    foreach ($p['errors'] as $err) {
                        CLI::error('  re-project ' . $err);
                    }
                    CLI::write("  Basic of {$a['product_code']}: {$p['queued']} changed state(s) queued for {$p['holders']} holder(s).");
                }
            }
            foreach ($r['failed'] as $f) {
                CLI::error("{$f['offer_code']}: {$f['error']}");
            }
        } catch (\Throwable $e) {
            CLI::error('Offer revision activation failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.offers_activate', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        $n = count($r['activated']);
        CLI::write("Offer revisions: {$n} took effect.");
        $failed = count($r['failed']);
        CronHeartbeat::send('engage.offers_activate', $failed === 0 ? 'ok' : 'error', $failed === 0 ? '' : "{$failed} revision(s) could not be recorded",
            ['activated' => $n, 'failed' => $failed, 'entitlements_queued' => $queued]);

        return $failed === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

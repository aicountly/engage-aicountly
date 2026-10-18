<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:commission-release` — accrued seller commission entries whose hold (plan hold_days,
 * the refund / clawback window) has passed become `approved`. Hourly:
 *   7 * * * * cd /path/to/api && php spark engage:commission-release >> writable/logs/commission_release.log 2>&1
 * Reports to Console's Cron Job Monitor as engage.commission_release (inert without CONSOLE_CRON_MONITOR_KEY).
 */
class CommissionRelease extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:commission-release';
    protected $description = 'Approve seller commission entries whose hold period has ended.';
    protected $usage       = 'engage:commission-release';

    public function run(array $params)
    {
        try {
            $n = Services::commissions()->releaseDue();
        } catch (\Throwable $e) {
            CLI::error('Commission release failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.commission_release', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        CLI::write("Commission release: {$n} entr" . ($n === 1 ? 'y' : 'ies') . ' approved.');
        CronHeartbeat::send('engage.commission_release', 'ok', '', ['approved' => $n]);

        return EXIT_SUCCESS;
    }
}

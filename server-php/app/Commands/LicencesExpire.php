<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:licences-expire` — issued / active / suspended licences whose term has ended become `expired`
 * (one `expired` event each, actor system). In the same transaction the holder's Basic is reactivated (D-L1: a paid
 * plan that ends falls back to Basic) and the new entitlement state is queued for Manage. Scheduled hourly by
 * bin/ops/jobs.txt (licences-expire). Reads already show an ended licence as expired before this runs. Never
 * restricts anything in Manage (no 45-day clock). Reports to Console's Cron Job Monitor as engage.licences_expire
 * (inert without CONSOLE_CRON_MONITOR_KEY).
 */
class LicencesExpire extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:licences-expire';
    protected $description = 'Mark licences whose term has ended as expired (with an activity event).';
    protected $usage       = 'engage:licences-expire';

    public function run(array $params)
    {
        try {
            $n = Services::licences()->expireDue();
        } catch (\Throwable $e) {
            CLI::error('Licence expiry failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.licences_expire', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        CLI::write("Licence expiry: {$n} licence" . ($n === 1 ? '' : 's') . ' expired.');
        CronHeartbeat::send('engage.licences_expire', 'ok', '', ['expired' => $n]);

        return EXIT_SUCCESS;
    }
}

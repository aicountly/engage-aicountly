<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:licences-expire` — issued / active / suspended licences whose term has ended become `expired`
 * (one `expired` event each, actor system). Not scheduled by the deploy (licensing is dormant until used); when
 * licences exist, add hourly:
 *   23 * * * * cd /path/to/api && php spark engage:licences-expire >> writable/logs/licences_expire.log 2>&1
 * Reads already show an ended licence as expired before this runs. Reports to Console's Cron Job Monitor as
 * engage.licences_expire (inert without CONSOLE_CRON_MONITOR_KEY).
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

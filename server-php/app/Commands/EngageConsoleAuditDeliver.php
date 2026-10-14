<?php

namespace App\Commands;

use App\Services\ConsoleAuditOutbox;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark engage:console-audit-deliver` — retry the central-audit events Console has not taken
 * yet (engage_console_audit_outbox, pending, due) to POST {CONSOLE_API_URL}/portal/audit in
 * batches of 100 (back-off 2, 4, 8 … minutes, at most 6 hours; dead after 12 attempts).
 *
 * Cron (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * cd /path/to/api && php spark engage:console-audit-deliver >> writable/logs/console_audit.log 2>&1
 */
class EngageConsoleAuditDeliver extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:console-audit-deliver';
    protected $description = 'Retry central-audit events Console has not taken yet (POST {CONSOLE_API_URL}/portal/audit).';
    protected $usage       = 'engage:console-audit-deliver';

    public function run(array $params)
    {
        $outbox = new ConsoleAuditOutbox();
        if (! $outbox->isConfigured()) {
            CLI::error('CONSOLE_API_URL / CONSOLE_API_TOKEN not set — nothing can be sent.');

            return EXIT_ERROR;
        }
        $r = $outbox->deliverDue();
        CLI::write(sprintf('Console audit: %d delivered, %d rejected, %d to retry, %d dead (%d batch%s).',
            $r['delivered'], $r['rejected'], $r['retry'], $r['dead'], $r['batches'], $r['batches'] === 1 ? '' : 'es'));

        return EXIT_SUCCESS;
    }
}

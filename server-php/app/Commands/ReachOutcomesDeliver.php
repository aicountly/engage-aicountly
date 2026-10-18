<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:reach-outcomes-deliver` — send queued lead outcome events to Reach
 * (POST {REACH_API_BASE_URL}/v1/inbound/engage/lead-outcomes, X-Engage-Token: REACH_OUTCOME_TOKEN).
 * Every 5 minutes:
 *   1,6,11,16,21,26,31,36,41,46,51,56 * * * * cd /path/to/api && php spark engage:reach-outcomes-deliver >> writable/logs/reach_outcomes.log 2>&1
 * Reports to Console's Cron Job Monitor as engage.reach_outcomes_deliver.
 */
class ReachOutcomesDeliver extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:reach-outcomes-deliver';
    protected $description = 'Deliver queued lead outcome events to Reach.';
    protected $usage       = 'engage:reach-outcomes-deliver';

    public function run(array $params)
    {
        $outbox = Services::reachOutcomes();
        if (! $outbox->isConfigured()) {
            // Like engage:console-audit-deliver: nothing is reported while unconfigured (events stay queued).
            CLI::error($outbox->status());

            return EXIT_ERROR;
        }
        $r = $outbox->deliverDue();
        CLI::write(sprintf('Reach outcomes: %d delivered, %d rejected, %d to retry, %d dead (%d batch%s).',
            $r['delivered'], $r['rejected'], $r['retry'], $r['dead'], $r['batches'], $r['batches'] === 1 ? '' : 'es'));
        CronHeartbeat::send('engage.reach_outcomes_deliver', 'ok', '', array_map('intval', $r));

        return EXIT_SUCCESS;
    }
}

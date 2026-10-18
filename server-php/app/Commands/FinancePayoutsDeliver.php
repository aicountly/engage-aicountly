<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:finance-payouts-deliver` — retry payout batches not yet accepted by finance.aicountly.org
 * (POST {FINANCE_API_BASE_URL}/v1/inbound/engage/payout-batches, X-Engage-Key: FINANCE_OUTBOUND_KEY). Every 5 minutes:
 *   3,8,13,18,23,28,33,38,43,48,53,58 * * * * cd /path/to/api && php spark engage:finance-payouts-deliver >> writable/logs/finance_payouts.log 2>&1
 * Reports to Console's Cron Job Monitor as engage.finance_payouts_deliver.
 */
class FinancePayoutsDeliver extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:finance-payouts-deliver';
    protected $description = 'Deliver queued seller payout batches to Finance.';
    protected $usage       = 'engage:finance-payouts-deliver';

    public function run(array $params)
    {
        $outbox = Services::financePayouts();
        if (! $outbox->isConfigured()) {
            CLI::error($outbox->status());

            return EXIT_ERROR;
        }
        $r = $outbox->deliverDue();
        CLI::write(sprintf('Finance payout batches: %d delivered, %d rejected, %d to retry, %d dead.', $r['delivered'], $r['rejected'], $r['retry'], $r['dead']));
        CronHeartbeat::send('engage.finance_payouts_deliver', 'ok', '', array_map('intval', $r));

        return EXIT_SUCCESS;
    }
}

<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Libraries\Licensing\CommandOptions;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:entitlements-push` — deliver queued entitlement states to Manage
 * (POST {MANAGE_API_BASE_URL}/v1/inbound/engage/entitlements, AIC-S2S v1 X-AIC-Client: engage; contract §3.3).
 * Scheduled every minute by bin/ops/jobs.txt. Strict seq order per holder; retries with back-off; rejected / dead
 * rows stay visible on /health (checks.entitlement_outbox) and Licensing → Manage sync.
 * Unconfigured (MANAGE_API_BASE_URL / ENGAGE_OUTBOUND_MANAGE_*) = dormant: rows stay pending, exit 0, nothing reported.
 * Reports to Console's Cron Job Monitor as engage.entitlements_push.
 */
class EntitlementsPush extends BaseCommand
{
    use CommandOptions;

    protected $group       = 'Engage';
    protected $name        = 'engage:entitlements-push';
    protected $description = 'Push queued licence entitlement states to Manage.';
    protected $usage       = 'engage:entitlements-push [--max=N]';

    public function run(array $params)
    {
        $outbox = Services::entitlementOutbox();
        if (! $outbox->client()->isConfigured()) {
            CLI::write('Entitlement push: ' . $outbox->client()->status());

            return EXIT_SUCCESS;
        }
        try {
            $r = $outbox->deliverDue(max(1, (int) (self::opt($params, 'max') ?? 500)));
        } catch (\Throwable $e) {
            CLI::error('Entitlement push failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.entitlements_push', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        CLI::write(sprintf('Entitlement push: %d sent — %d delivered, %d rejected, %d to retry, %d dead; %d skipped (newer state queued).',
            $r['sent'], $r['delivered'], $r['rejected'], $r['retry'], $r['dead'], $r['skipped']));
        CronHeartbeat::send('engage.entitlements_push', 'ok', '', array_map('intval', $r));

        return EXIT_SUCCESS;
    }
}

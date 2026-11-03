<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Services\Tokens\TokenAlertDelivery;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark engage:token-alerts-send [--limit N]` — every 15 minutes.
 *
 * Collects the AI-balance warnings Pulse has worked out and delivers them through
 * Console's notification hub. Pulse decides when a customer should be told; Engage
 * has the address and the Console key, so Engage does the telling.
 *
 * Inert, and exits 0, until both sides are configured: an estate that has not wired
 * this yet is not a failing job. Alerts wait in Pulse until it is.
 */
class TokenAlertsSend extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:token-alerts-send';
    protected $description = 'Deliver AI token balance warnings collected from Pulse.';
    protected $usage       = 'engage:token-alerts-send [--limit N]';
    protected $options     = ['--limit' => 'Most alerts to collect in this run (default 200).'];

    public function run(array $params)
    {
        try {
            $r = (new TokenAlertDelivery())->run((int) (CLI::getOption('limit') ?: 200));
        } catch (\Throwable $e) {
            CLI::error('Token alert delivery failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.token_alerts_send', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }

        if (! $r['configured']) {
            CLI::write('Token alerts: Pulse or Console is not configured here; nothing collected.', 'yellow');
            CronHeartbeat::send('engage.token_alerts_send', 'ok', 'not configured');

            return EXIT_SUCCESS;
        }
        if ($r['error'] !== null) {
            CLI::error('Token alerts: ' . $r['error']);
            CronHeartbeat::send('engage.token_alerts_send', 'error', $r['error']);

            return EXIT_ERROR;
        }

        CLI::write("Token alerts: {$r['collected']} collected, {$r['sent']} sent, {$r['skipped']} not sent.", 'green');
        if ($r['skipped'] > 0) {
            // Overwhelmingly: no e-mail recorded for the holder, or an SMS/WhatsApp
            // template still awaiting DLT or Meta approval. Both are expected today.
            CLI::write('Not sent is usually a missing address or a template still awaiting approval; Pulse re-arms those.', 'yellow');
        }
        CronHeartbeat::send('engage.token_alerts_send', 'ok', '', $r);

        return EXIT_SUCCESS;
    }
}

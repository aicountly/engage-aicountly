<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Services\Tokens\TokenRatePusher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark engage:token-rates-push [--dry-run]` (hourly, bin/ops/jobs.txt) —
 * docs/TOKEN_CONVERSIONS.md.
 *
 * Sends AI Pulse the rate card: one effective multiplier per model, already resolved, so
 * Pulse looks a number up rather than re-deriving one and charging something other than
 * what Engage showed the customer.
 *
 * Safe to run at any time and safe to repeat: Pulse keeps the last card it was given, so a
 * failed push changes nothing rather than dropping the estate back to default pricing. On a
 * schedule because a rate activated in Engage should reach Pulse without anyone remembering,
 * and because a model registered in Console between pushes is otherwise priced by Pulse's own
 * fallback until the next one.
 *
 * Reports to Console's Cron Job Monitor as engage.token_rates_push (inert without
 * CONSOLE_CRON_MONITOR_KEY). Inert, and exits 0, until PULSE_API_BASE_URL and the outbound
 * key are set — an unconfigured estate is not a failing job.
 */
class TokenRatesPush extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:token-rates-push';
    protected $description = 'Send AI Pulse the token conversion rate card.';
    protected $usage       = 'engage:token-rates-push [--dry-run]';
    protected $options     = ['--dry-run' => 'Show the card that would be sent; send nothing.'];

    public function run(array $params)
    {
        $dry = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $pusher = new TokenRatePusher();

        if ($dry) {
            $card = $pusher->card();
            CLI::write(json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            CLI::write("Dry run: {$card['models']} model(s) priced; nothing sent.", 'yellow');

            return EXIT_SUCCESS;
        }

        try {
            $r = $pusher->push();
        } catch (\Throwable $e) {
            CLI::error('Token rate push failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.token_rates_push', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }

        if ($r['ok']) {
            CLI::write("Token rate card: {$r['models']} model(s) sent to Pulse.", 'green');
            CronHeartbeat::send('engage.token_rates_push', 'ok', '', ['models' => $r['models']]);

            return EXIT_SUCCESS;
        }

        // Not configured is not a failure: an estate that has not wired this yet should not
        // page anyone every hour.
        if ($r['status'] === 0 && str_starts_with($r['message'], 'Not configured')) {
            CLI::write('Token rate card: ' . $r['message'], 'yellow');
            CronHeartbeat::send('engage.token_rates_push', 'ok', $r['message']);

            return EXIT_SUCCESS;
        }

        CLI::error('Token rate card not accepted: ' . $r['message']);
        CLI::write('Pulse keeps the card it already has, so pricing is unchanged.', 'yellow');
        CronHeartbeat::send('engage.token_rates_push', 'error', substr($r['message'], 0, 500));

        return EXIT_ERROR;
    }
}

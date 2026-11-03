<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Services\Tokens\TokenGrantPusher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark engage:token-grants-push [--limit N]` (every minute, bin/ops/jobs.txt) —
 * docs/TOKEN_CONVERSIONS.md §10.
 *
 * Delivers queued token grants to AI Pulse. Tokens are queued when a voucher is redeemed or
 * a purchase is confirmed, never pushed inline: a customer's credit must not depend on Pulse
 * being reachable at that instant. Pulse is idempotent on grant_ref, so a retry credits once.
 *
 * Reports to Console's Cron Job Monitor as engage.token_grants_push. An unconfigured estate
 * is not a failing job; a DEAD grant is, because it names a customer who is owed tokens and
 * nobody is coming unless somebody is told.
 */
class TokenGrantsPush extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:token-grants-push';
    protected $description = 'Deliver queued token grants to AI Pulse.';
    protected $usage       = 'engage:token-grants-push [--limit N]';
    protected $options     = ['--limit' => 'Most grants to send in this run (default 200).'];

    public function run(array $params)
    {
        $limit = (int) (CLI::getOption('limit') ?: 200);
        try {
            $r = (new TokenGrantPusher())->drain($limit > 0 ? $limit : 200);
        } catch (\Throwable $e) {
            CLI::error('Token grant delivery failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.token_grants_push', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }

        if (! $r['configured']) {
            CLI::write("Token grants: Pulse is not configured here; {$r['pending']} grant(s) waiting.", 'yellow');
            CronHeartbeat::send('engage.token_grants_push', 'ok', 'not configured', ['pending' => $r['pending']]);

            return EXIT_SUCCESS;
        }

        $msg = "Token grants: {$r['sent']} delivered, {$r['failed']} to retry, {$r['dead']} dead, {$r['pending']} pending.";
        CLI::write($msg, $r['dead'] > 0 ? 'red' : 'green');
        if ($r['dead'] > 0) {
            CLI::write('A dead grant is a customer owed tokens. Fix the cause, then requeue it.', 'red');
            CronHeartbeat::send('engage.token_grants_push', 'error', $msg, $r);

            return EXIT_ERROR;
        }
        CronHeartbeat::send('engage.token_grants_push', 'ok', '', $r);

        return EXIT_SUCCESS;
    }
}

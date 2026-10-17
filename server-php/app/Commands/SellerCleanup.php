<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark engage:seller-cleanup` — daily housekeeping for the seller portal:
 *   - expire idle (> 8 h) / old (> 7 d) seller sessions; delete session rows ended more than 30 days ago;
 *   - delete one-time tokens (activation / reset / OTP) that expired or were used more than 30 days ago;
 *   - delete Idempotency-Key replays older than 24 hours.
 *   17 3 * * * cd /path/to/api && php spark engage:seller-cleanup >> writable/logs/seller_cleanup.log 2>&1
 * Reports to Console's Cron Job Monitor as engage.seller_cleanup.
 */
class SellerCleanup extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:seller-cleanup';
    protected $description = 'Expire / purge old seller sessions, one-time tokens and idempotency records.';
    protected $usage       = 'engage:seller-cleanup';

    public function run(array $params)
    {
        $db = Database::connect();
        try {
            $db->query("UPDATE engage_seller_sessions SET revoked_at = NOW(), revoked_reason = 'expired'
                         WHERE revoked_at IS NULL AND (expires_at <= NOW() OR last_seen_at <= NOW() - INTERVAL '8 hours')");
            $expired = $db->affectedRows();
            $db->query("DELETE FROM engage_seller_sessions WHERE revoked_at < NOW() - INTERVAL '30 days'");
            $sessions = $db->affectedRows();
            $db->query("DELETE FROM engage_seller_tokens WHERE (used_at IS NOT NULL AND used_at < NOW() - INTERVAL '30 days')
                            OR (revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL '30 days')
                            OR expires_at < NOW() - INTERVAL '30 days'");
            $tokens = $db->affectedRows();
            $db->query("DELETE FROM engage_idempotency_keys WHERE created_at < NOW() - INTERVAL '24 hours'");
            $idem = $db->affectedRows();
        } catch (\Throwable $e) {
            CLI::error('Seller cleanup failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.seller_cleanup', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        CLI::write("Seller cleanup: {$expired} sessions expired, {$sessions} old sessions, {$tokens} tokens, {$idem} idempotency records deleted.");
        CronHeartbeat::send('engage.seller_cleanup', 'ok', '', ['sessions_expired' => $expired, 'sessions_deleted' => $sessions, 'tokens_deleted' => $tokens, 'idempotency_deleted' => $idem]);

        return EXIT_SUCCESS;
    }
}

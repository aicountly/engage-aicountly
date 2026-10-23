<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * engage_coupon_attempts: every code check is logged (channel, scope key = holder / staff, sha256 of the code — never the
 * code itself, outcome, detailed reason, IP) and drives the per-holder limits on the Manage routes:
 *
 *   manage_preview   ENGAGE_COUPON_PREVIEW_LIMIT (default 10) checks per ENGAGE_COUPON_PREVIEW_WINDOW_SECONDS (600) per holder
 *   manage_upgrade   ENGAGE_UPGRADE_REQUEST_LIMIT (default 5) requests per ENGAGE_UPGRADE_REQUEST_WINDOW_SECONDS (3600) per holder
 *
 * Staff quotes are logged but not limited. Rows older than 90 days are pruned by engage:coupons-expire.
 */
final class AttemptLimiter
{
    public const DEFAULTS = [
        'manage_preview' => ['ENGAGE_COUPON_PREVIEW_LIMIT', 10, 'ENGAGE_COUPON_PREVIEW_WINDOW_SECONDS', 600],
        'manage_upgrade' => ['ENGAGE_UPGRADE_REQUEST_LIMIT', 5, 'ENGAGE_UPGRADE_REQUEST_WINDOW_SECONDS', 3600],
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** @return array{limit:int, window:int} */
    public static function config(string $channel): array
    {
        [$lk, $ld, $wk, $wd] = self::DEFAULTS[$channel];
        $limit = (int) env($lk, $ld);
        $window = (int) env($wk, $wd);

        return ['limit' => $limit > 0 ? $limit : $ld, 'window' => $window > 0 ? $window : $wd];
    }

    /** Seconds to wait when $scopeKey has used up its allowance on $channel, else null. */
    public function retryAfter(string $channel, string $scopeKey): ?int
    {
        if (! isset(self::DEFAULTS[$channel])) {
            return null;
        }
        $c = self::config($channel);
        $row = $this->db()->query(
            "SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM engage_coupon_attempts
              WHERE channel = ? AND scope_key = ? AND created_at > NOW() - make_interval(secs => ?)",
            [$channel, $scopeKey, $c['window']]
        )->getRowArray();
        if ((int) $row['n'] < $c['limit']) {
            return null;
        }

        return max(1, $c['window'] - (time() - (int) strtotime((string) $row['oldest'])));
    }

    public function record(string $channel, string $scopeKey, ?string $code, ?int $couponId, string $outcome, ?string $reason = null, ?string $ip = null): void
    {
        $this->db()->query(
            'INSERT INTO engage_coupon_attempts (channel, scope_key, code_sha256, coupon_id, outcome, reason, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$channel, mb_substr($scopeKey, 0, 96), $code !== null && $code !== '' ? CodeGenerator::fingerprint($code) : null, $couponId,
                mb_substr($outcome, 0, 24), $reason !== null ? mb_substr($reason, 0, 48) : null, $ip !== null ? mb_substr($ip, 0, 64) : null]
        );
    }

    public function prune(int $days = 90): int
    {
        $this->db()->query('DELETE FROM engage_coupon_attempts WHERE created_at < NOW() - make_interval(days => ?)', [$days]);

        return $this->db()->affectedRows();
    }
}

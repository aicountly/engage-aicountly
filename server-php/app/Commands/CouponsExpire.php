<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Services\Coupons\AttemptLimiter;
use App\Services\Coupons\CouponService;
use App\Services\Coupons\RedemptionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark engage:coupons-expire` (hourly, bin/ops/jobs.txt) — docs/COUPONS.md §5:
 *   - reserved coupon redemptions past reserved_until → released (a single-use code becomes available again);
 *   - open quotes past expires_at → expired;
 *   - active / paused coupons past valid_to → expired;
 *   - coupon attempt rows older than 90 days are pruned.
 * Reads already treat a lapsed reservation as released and an ended coupon as expired before this runs. Reports to
 * Console's Cron Job Monitor as engage.coupons_expire (inert without CONSOLE_CRON_MONITOR_KEY).
 */
class CouponsExpire extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:coupons-expire';
    protected $description = 'Release lapsed coupon reservations, expire old quotes and ended coupons.';
    protected $usage       = 'engage:coupons-expire';

    public function run(array $params)
    {
        try {
            $r = (new RedemptionService())->expireStale();
            $coupons = (new CouponService())->expireEnded();
            $pruned = (new AttemptLimiter())->prune();
        } catch (\Throwable $e) {
            CLI::error('Coupon expiry failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.coupons_expire', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        CLI::write("Coupon expiry: {$r['released']} reservation(s) released, {$r['quotes_expired']} quote(s) expired, {$coupons} coupon(s) expired, {$pruned} attempt row(s) pruned.");
        CronHeartbeat::send('engage.coupons_expire', 'ok', '', ['released' => $r['released'], 'quotes_expired' => $r['quotes_expired'], 'coupons_expired' => $coupons]);

        return EXIT_SUCCESS;
    }
}

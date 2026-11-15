<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use App\Services\SellerLicences\SellerLicenceOrderService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark engage:seller-orders-expire` — sellers' licence orders still unpaid when their validity
 * (seller_order_validity_days) ends become `expired` (docs/SELLING_LICENCES.md §7). Reads already show them expired;
 * a payment that still arrives for the exact total settles the order. Hourly (bin/ops/jobs.txt, seller-orders-expire);
 * reports to Console's Cron Job Monitor as engage.seller_orders_expire.
 */
class SellerOrdersExpire extends BaseCommand
{
    protected $group       = 'Engage';
    protected $name        = 'engage:seller-orders-expire';
    protected $description = 'Expire seller licence orders left unpaid past their validity.';
    protected $usage       = 'engage:seller-orders-expire';

    public function run(array $params)
    {
        try {
            $n = (new SellerLicenceOrderService())->expireDue();
        } catch (\Throwable $e) {
            CLI::error('Seller order expiry failed: ' . $e->getMessage());
            CronHeartbeat::send('engage.seller_orders_expire', 'error', substr($e->getMessage(), 0, 500));

            return EXIT_ERROR;
        }
        CLI::write("Seller licence orders: {$n} expired.");
        CronHeartbeat::send('engage.seller_orders_expire', 'ok', '', ['expired' => $n]);

        return EXIT_SUCCESS;
    }
}

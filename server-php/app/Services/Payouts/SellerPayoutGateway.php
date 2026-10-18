<?php
declare(strict_types=1);

namespace App\Services\Payouts;

/**
 * The payout seam between commission bookkeeping and finance.aicountly.org (docs/SELLER_PROGRAM.md § 6).
 * Implemented by SellerPayoutService:
 *
 *   - buildWeeklyRun(): the run for a week (Monday 10:00 IST, `php spark engage:seller-payout-run`): per active +
 *     kyc_verified seller, approved commission entries not in a payout; below the minimum (setting
 *     seller_payout_min_threshold, default ₹500) rolls over; entries → in_payout with payout_item_ref; a DRAFT batch
 *     POSTed to finance /api/v1/inbound/engage/payout-batches (X-Engage-Key = FINANCE_OUTBOUND_KEY), idempotent
 *     on batch_ref. A person in Finance reviews and finalises it.
 *   - applyFinanceStatus(): POST engage /api/v1/inbound/finance/payout-status from Finance
 *     (X-Finance-Key = ENGAGE_INBOUND_FINANCE_KEY) — approved | on_hold | paid | failed | reversed | cancelled per item;
 *     `paid` needs a UTR and moves entries to paid; failed / reversed / cancelled return them to approved.
 */
interface SellerPayoutGateway
{
    /**
     * @return array{batch_ref:string, items:int, gross_amount:string}
     */
    public function buildWeeklyRun(string $periodStart, string $periodEnd): array;

    /**
     * @param array{batch_ref:string, item_ref:string, status:string, tds_amount:?string, gst_amount:?string,
     *              net_amount:?string, utr:?string, paid_at:?string, reason:?string} $status
     * @return array{status:int, data?:array, error?:string, code?:string, details?:array}
     */
    public function applyFinanceStatus(array $status): array;
}

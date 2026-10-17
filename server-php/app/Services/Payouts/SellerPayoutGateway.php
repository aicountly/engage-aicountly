<?php
declare(strict_types=1);

namespace App\Services\Payouts;

/**
 * SEAM ONLY — weekly seller payouts are on hold pending the product owner's decision
 * (docs/SELLER_PROGRAM.md § "Weekly payouts (pending)"). Nothing implements this interface and no
 * route, command or cron calls it. It records the shape the contract fixes so the payout stream can
 * plug in without touching commission bookkeeping:
 *
 *   - buildWeeklyRun(): every Monday 10:00 IST, per active + kyc_verified seller, sum `approved`
 *     engage_commission_entries not yet paid; skip below the minimum payout threshold (setting, default
 *     ₹500 — rolls over); mark the entries `in_payout` with payout_item_ref; POST the batch to
 *     finance.aicountly.org /api/v1/inbound/engage/payout-batches (X-Engage-Key / FINANCE_INBOUND_ENGAGE_KEY),
 *     idempotent on batch_ref.
 *   - applyFinanceStatus(): POST /api/v1/inbound/finance/payout-status from Finance
 *     (X-Finance-Key / ENGAGE_INBOUND_FINANCE_KEY) — approved | on_hold | paid | failed | reversed per item;
 *     `paid` (with a UTR) moves entries to paid; failed / reversed returns them to approved.
 *
 * Until then: entries stop at `approved`, and GET v1/seller-portal/payouts answers
 * {enabled: false, status: "payouts not enabled yet", items: []}.
 */
interface SellerPayoutGateway
{
    /**
     * @return array{batch_ref:string, items:int, gross_amount:string}
     */
    public function buildWeeklyRun(string $periodStart, string $periodEnd): array;

    /**
     * @param array{batch_ref:string, item_ref:string, status:string, tds_amount:string, gst_amount:string,
     *              net_amount:string, utr:?string, paid_at:?string, reason:?string} $status
     */
    public function applyFinanceStatus(array $status): void;
}

<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller earnings (session):
 *   GET commissions  ?status= &page= &limit= → {plan_published, message ("No commission plan published yet." when none),
 *                     plans, currency "INR", totals {accrued, approved, in_payout, paid, reversed}, items, total}
 *   GET payouts      → {enabled: false, status: "payouts not enabled yet", items: []}
 *                     Weekly payouts are on hold (docs/SELLER_PROGRAM.md); nothing here pretends otherwise.
 */
class EarningsController extends BaseSellerPortalController
{
    public function commissions()
    {
        [$page, $limit, $offset] = $this->paging();
        $status = (string) ($this->request->getGet('status') ?? '');
        $r = Services::commissions()->sellerSummary((int) $this->seller()['id'], min($limit, 100), $offset, $status !== '' ? $status : null);

        return $this->ok($r + ['page' => $page, 'limit' => min($limit, 100)]);
    }

    public function payouts()
    {
        return $this->ok([
            'enabled' => false,
            'status'  => 'payouts not enabled yet',
            'message' => 'Weekly payouts are not enabled yet. Approved commission stays on your account until they are.',
            'items'   => [],
        ]);
    }
}

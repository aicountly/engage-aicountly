<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller earnings (portal key + X-Seller-Session):
 *   GET commissions ?status= &page= &limit= → {plan: {published, name, version}, message, currency, totals, items, page, limit, total}
 *   GET payouts                            → {enabled: false, status: "payouts not enabled yet", items: [], page, limit, total: 0}
 *   GET payouts/{ref}, payouts/{ref}/statement → 404 not_found (no payout exists yet)
 * Weekly payouts are on hold (docs/SELLER_PROGRAM.md § Weekly payouts — pending); nothing here pretends otherwise.
 */
class EarningsController extends BaseSellerPortalController
{
    public function commissions()
    {
        [$page, $limit, $offset] = $this->pageParams();
        $status = (string) ($this->request->getGet('status') ?? '');
        $r = Services::commissions()->sellerSummary((int) $this->seller()['id'], $limit, $offset, $status !== '' ? $status : null);

        return $this->ok($r + ['page' => $page, 'limit' => $limit]);
    }

    public function payouts()
    {
        [$page, $limit] = $this->pageParams();

        return $this->ok([
            'enabled' => false,
            'status'  => 'payouts not enabled yet',
            'message' => 'Weekly payouts are not enabled yet. Approved commission stays on your account until they are.',
            'items'   => [],
            'page'    => $page,
            'limit'   => $limit,
            'total'   => 0,
        ]);
    }

    public function payout($ref = null)
    {
        return $this->failCode(404, 'not_found', 'Payouts are not enabled yet.');
    }
}

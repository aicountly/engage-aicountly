<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller earnings (portal key + X-Seller-Session):
 *   GET commissions ?status= &page= &limit= → {plan: {published, name, version}, message, currency, totals, items, page, limit, total}
 *   GET payouts ?status= &page= &limit=    → {items: [{payout_ref, batch_ref, period_start, period_end, gross_amount, tds_amount,
 *                                             gst_amount, net_amount, utr, status, paid_at, reason, entries_count}], page, limit, total}
 *   GET payouts/{payout_ref}                → {payout: {…item, tds_section, entries, payee, issuer, generated_at}}; 404 if not this seller's
 *   GET payouts/{payout_ref}/statement      → text/html statement (no JSON envelope); errors in the JSON envelope
 * Status: processing (draft with Finance) | approved | on_hold | paid | failed | reversed | cancelled. TDS / GST / net are
 * null until Finance reports them; paid only with a UTR (docs/SELLER_PROGRAM.md § 6).
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
        [$page, $limit, $offset] = $this->pageParams();
        $status = (string) ($this->request->getGet('status') ?? '');
        $r = Services::sellerPayouts()->sellerPayouts((int) $this->seller()['id'], $limit, $offset, $status !== '' ? $status : null);

        return $this->ok($r + ['page' => $page, 'limit' => $limit]);
    }

    public function payout($ref = null)
    {
        $p = $this->find((string) $ref);

        return $p === null ? $this->failCode(404, 'not_found', 'Payout not found.') : $this->ok(['payout' => $p]);
    }

    public function statement($ref = null)
    {
        $p = $this->find((string) $ref);
        if ($p === null) {
            return $this->failCode(404, 'not_found', 'Payout not found.');
        }
        $html = \App\Services\Payouts\SellerPayoutService::statementHtml($p);

        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Content-Disposition', 'inline; filename="aicountly-seller-statement-' . $p['payout_ref'] . '.html"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($html);
    }

    private function find(string $ref): ?array
    {
        if (! preg_match('/\A[A-Za-z0-9_\-]{1,64}\z/', $ref)) {
            return null;
        }

        return Services::sellerPayouts()->sellerPayout((int) $this->seller()['id'], $ref);
    }
}

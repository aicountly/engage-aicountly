<?php

namespace App\Controllers\SellerPortal;

use App\Services\SellerLicences\SellerLicenceApplyService;
use App\Services\SellerLicences\SellerLicenceOrderService;
use Config\Services;

/**
 * Sellers buy licences and apply them to their customers (docs/SELLING_LICENCES.md §7; seller-aicountly
 * docs/ENGAGE_SELLER_API.md "Licences"). Portal key + X-Seller-Session; writes are idempotent (Idempotency-Key).
 *
 *   GET  licence-catalogue                         what the seller can buy, with the commission plan per product
 *   GET  licence-orders ?status=&page=&limit=      the seller's orders
 *   POST licence-orders {lines:[{offer_code, quantity}]}             → 201 the order (price frozen; how to pay)
 *   GET  licence-orders/{order_ref}
 *   POST licence-orders/{order_ref}/cancel {reason}                  while unpaid
 *   POST licence-orders/{order_ref}/payment-link                     raise / re-raise the payment link (when online)
 *   GET  licence-stock ?status=available|applied|void &product_code= &page= &limit=
 *   POST customers/lookup {email}                                    masked: is there an account, may I sell to it
 *   POST licence-stock/{unit_ref}/apply {customer_email, consent_confirmed: true}   → new licence or renewal
 *   GET  customer-licences ?page=&limit=                             licences this seller sold, for renewals
 */
class LicencesController extends BaseSellerPortalController
{
    private function orders(): SellerLicenceOrderService
    {
        return new SellerLicenceOrderService();
    }

    private function idem(): ?string
    {
        $k = trim((string) $this->request->getHeaderLine('Idempotency-Key'));

        return $k !== '' ? $k : null;
    }

    public function catalogue()
    {
        return $this->ok($this->orders()->catalogue($this->seller()));
    }

    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams();
        $status = $this->request->getGet('status');
        $r = $this->orders()->listForSeller((int) $this->seller()['id'], is_string($status) ? $status : null, $limit, $offset);

        return $this->ok(['items' => $r['items'], 'page' => $page, 'limit' => $limit, 'total' => $r['total']]);
    }

    public function create()
    {
        if ($b = $this->writeBlock()) {
            return $b;
        }
        if ($limited = $this->throttle('licence-order:' . $this->seller()['id'], 30, HOUR)) {
            return $limited;
        }

        return $this->result($this->orders()->create($this->seller(), $this->input(), $this->idem()));
    }

    public function show($ref = null)
    {
        $o = $this->orders()->byRef((string) $ref, (int) $this->seller()['id']);

        return $o ? $this->ok(['order' => $this->orders()->sellerView($o)]) : $this->failCode(404, 'not_found', 'No such licence order.');
    }

    public function cancel($ref = null)
    {
        if ($b = $this->writeBlock()) {
            return $b;
        }

        return $this->result($this->orders()->cancel((string) $ref, (string) ($this->input()['reason'] ?? ''), 'seller', (int) $this->seller()['id']));
    }

    public function paymentLink($ref = null)
    {
        if ($b = $this->writeBlock()) {
            return $b;
        }
        $o = $this->orders()->byRef((string) $ref, (int) $this->seller()['id']);
        if (! $o) {
            return $this->failCode(404, 'not_found', 'No such licence order.');
        }
        if ($limited = $this->throttle('licence-link:' . $o['id'], 10, HOUR)) {
            return $limited;
        }

        return $this->result($this->orders()->raiseLink((int) $o['id']));
    }

    public function stock()
    {
        [$page, $limit, $offset] = $this->pageParams();
        $r = (new SellerLicenceApplyService())->stock($this->seller(), (array) $this->request->getGet(), $limit, $offset);

        return $this->ok($r + ['page' => $page, 'limit' => $limit]);
    }

    public function lookup()
    {
        if ($b = $this->writeBlock()) {
            return $b;
        }
        if ($limited = $this->throttle('customer-lookup:' . $this->seller()['id'], 30, HOUR)) {
            return $limited;
        }

        return $this->result((new SellerLicenceApplyService())->lookup($this->seller(), (string) ($this->input()['email'] ?? '')));
    }

    public function apply($unitRef = null)
    {
        if ($b = $this->writeBlock()) {
            return $b;
        }
        if ($limited = $this->throttle('licence-apply:' . $this->seller()['id'], 120, HOUR)) {
            return $limited;
        }

        return $this->result((new SellerLicenceApplyService())->apply($this->seller(), (string) $unitRef, $this->input(), $this->idem()));
    }

    public function customerLicences()
    {
        [$page, $limit, $offset] = $this->pageParams();
        $r = (new SellerLicenceApplyService())->customerLicences($this->seller(), (array) $this->request->getGet(), $limit, $offset);

        return $this->ok($r + ['page' => $page, 'limit' => $limit]);
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use App\Services\Licensing\OfferService;
use Config\Services;

/**
 * Staff: offers — the versioned catalogue (read: every signed-in role; write: super_admin | sales_manager).
 *   GET    v1/offers ?status=&offer_code=&product_code=      GET v1/offers/{id}
 *   POST   v1/offers                       → draft v1
 *   PUT    v1/offers/{id}                  draft only (published versions are immutable)
 *   DELETE v1/offers/{id}                  discard a draft
 *   POST   v1/offers/{id}/publish | retire | new-version
 *                                          publish: a draft whose valid_from is still ahead is SCHEDULED (a price revision
 *                                          with an effective date); otherwise it goes on sale now
 *   POST   v1/offers/{id}/revise   {price_paise, price_basis?, gst_rate?, effective_from, note}
 *                                          price revision "with effect from" of the version in effect now (201)
 *   POST   v1/offers/{id}/cancel-schedule {reason}   withdraw a scheduled revision before it takes effect
 *   POST   v1/offers/{id}/channels {channels: [website|manage|seller…]}   where this version is sold
 *   GET    v1/offers/price-history/{offer_code}      every version with the period its price applied
 *   POST   v1/offers/{id}/default  {is_default: bool}   the product's Basic (draft or published; one published per product)
 *   GET    v1/offers/basic-readiness       which products still have no published Basic
 */
class OffersController extends LicensingBaseController
{
    public function index()
    {
        $q = $this->request->getGet();

        return $this->ok(['items' => Services::offers()->list(is_array($q) ? $q : [])]);
    }

    /**
     * Which licensable products still have no published default (Basic) offer.
     *
     * Every signed-up person is meant to hold the free Basic of every app (D-L1), but
     * provisioning can only issue a Basic that EXISTS: a product without a published default
     * offer is skipped, the call still answers 200, and nobody is told. This is the endpoint
     * that tells them — Engage shows a banner from it and Console raises a warning.
     *
     * `draft` separates the two cases that need different sentences: someone ran
     * `engage:basic-offers`, filled nothing in and never published (draft), versus nobody has
     * started at all.
     */
    public function basicReadiness()
    {
        $missing = Services::offers()->productsWithoutDefaultOffer();

        return $this->ok([
            'ready'   => $missing === [],
            'missing' => $missing,
            'counts'  => [
                'missing' => count($missing),
                'draft'   => count(array_filter($missing, static fn (array $p): bool => $p['draft'])),
            ],
        ]);
    }

    public function show($id = null)
    {
        $o = Services::offers()->findRow((int) $id);

        return $o ? $this->ok(OfferService::view($o)) : $this->fail('Offer not found.', 404);
    }

    public function create()
    {
        return $this->result(Services::offers()->createDraft($this->input(), $this->user()));
    }

    public function update($id = null)
    {
        return $this->result(Services::offers()->updateDraft((int) $id, $this->input(), $this->user()));
    }

    public function delete($id = null)
    {
        return $this->result(Services::offers()->discardDraft((int) $id, $this->user()));
    }

    public function publish($id = null)
    {
        return $this->result(Services::offers()->publish((int) $id, $this->user()));
    }

    public function retire($id = null)
    {
        return $this->result(Services::offers()->retire((int) $id, $this->user()));
    }

    public function newVersion($id = null)
    {
        return $this->result(Services::offers()->newVersion((int) $id, $this->user()));
    }

    public function revise($id = null)
    {
        return $this->result(Services::offers()->revise((int) $id, $this->input(), $this->user()));
    }

    public function cancelSchedule($id = null)
    {
        return $this->result(Services::offers()->cancelScheduled((int) $id, (string) ($this->input()['reason'] ?? ''), $this->user()));
    }

    public function channels($id = null)
    {
        $in = $this->input();
        if (! array_key_exists('channels', $in)) {
            return $this->fail('Send channels: a list of website, manage, seller (empty = staff only).', 422, ['channels' => 'Required.']);
        }

        return $this->result(Services::offers()->setChannels((int) $id, $in['channels'], $this->user()));
    }

    public function priceHistory($code = null)
    {
        $h = Services::offers()->history((string) $code);

        return $h ? $this->ok($h) : $this->fail('Offer not found.', 404);
    }

    public function setDefault($id = null)
    {
        $in = $this->input();
        if (! array_key_exists('is_default', $in)) {
            return $this->fail('Send is_default (true or false).', 422, ['is_default' => 'Required.']);
        }

        return $this->result(Services::offers()->setDefault((int) $id, filter_var($in['is_default'], FILTER_VALIDATE_BOOLEAN), $this->user()));
    }
}

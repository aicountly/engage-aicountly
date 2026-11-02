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

    public function setDefault($id = null)
    {
        $in = $this->input();
        if (! array_key_exists('is_default', $in)) {
            return $this->fail('Send is_default (true or false).', 422, ['is_default' => 'Required.']);
        }

        return $this->result(Services::offers()->setDefault((int) $id, filter_var($in['is_default'], FILTER_VALIDATE_BOOLEAN), $this->user()));
    }
}

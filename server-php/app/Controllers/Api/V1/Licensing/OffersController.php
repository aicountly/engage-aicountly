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
 */
class OffersController extends LicensingBaseController
{
    public function index()
    {
        $q = $this->request->getGet();

        return $this->ok(['items' => Services::offers()->list(is_array($q) ? $q : [])]);
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
}

<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * Staff: seller agreement versions (super_admin | seller_manager to write; seller roles to read).
 *   GET v1/seller-agreements · POST v1/seller-agreements {version_label, title, body}
 *   PUT v1/seller-agreements/{id} (draft only) · POST v1/seller-agreements/{id}/publish
 */
class SellerAgreementsController extends BaseApiController
{
    public function index()
    {
        return $this->ok(['items' => Services::sellerAgreements()->list()]);
    }

    public function create()
    {
        return $this->respondWith(Services::sellerAgreements()->create($this->input(), $this->user()));
    }

    public function update($id = null)
    {
        return $this->respondWith(Services::sellerAgreements()->update((int) $id, $this->input(), $this->user()));
    }

    public function publish($id = null)
    {
        return $this->respondWith(Services::sellerAgreements()->publish((int) $id, $this->user()));
    }
}

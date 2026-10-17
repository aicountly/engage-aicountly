<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller leads (session):
 *   GET  leads          ?status=registered|in_progress|converted|lost|closed &q= &page= &limit=
 *   POST leads          {name, email?, mobile? (one required), organization?, interested_product?, city?, expected_users?, notes?}
 *                       → 201 {lead, result: created|claimed}; 409 duplicate_lead | lead_protected (clear message)
 *   GET  leads/{code}   → one lead registered by / attributed to this seller
 */
class LeadsController extends BaseSellerPortalController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->paging();
        $r = Services::sellerLeads()->list($this->seller(), (array) $this->request->getGet(), min($limit, 100), $offset);

        return $this->ok($r + ['page' => $page, 'limit' => min($limit, 100)]);
    }

    public function create()
    {
        if ($r = $this->requireSettled()) {
            return $r;
        }
        if ($limited = $this->throttle('lead-register:' . $this->seller()['id'], 60, HOUR)) {
            return $limited;
        }

        return $this->result(Services::sellerLeads()->register($this->seller(), $this->input()));
    }

    public function show($code = null)
    {
        $l = Services::sellerLeads()->show($this->seller(), (string) $code);

        return $l ? $this->ok($l) : $this->failCode(404, 'not_found', 'Lead not found.');
    }
}

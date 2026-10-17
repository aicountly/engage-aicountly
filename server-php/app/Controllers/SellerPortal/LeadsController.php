<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller leads (portal key + X-Seller-Session):
 *   GET  leads ?status=registered|contacted|qualified|demo|proposal_sent|converted|lost|expired &q= &page= &limit=
 *   POST leads {name, company?, email?, mobile? (one required), city?, state?, interest?, notes?, consent_confirmed: true}
 *        → 201 {lead: {lead_code, name, status, registered_at, protected_until}}; 409 duplicate_lead | lead_protected
 *   GET  leads/{lead_code}   (Engage extra)
 */
class LeadsController extends BaseSellerPortalController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pageParams();
        $r = Services::sellerLeads()->list($this->seller(), (array) $this->request->getGet(), $limit, $offset);

        return $this->ok(['items' => $r['items'], 'page' => $page, 'limit' => $limit, 'total' => $r['total']]);
    }

    public function create()
    {
        if ($limited = $this->throttle('lead-register:' . $this->seller()['id'], 60, HOUR)) {
            return $limited;
        }

        return $this->result(Services::sellerLeads()->register($this->seller(), $this->input()));
    }

    public function show($code = null)
    {
        $l = Services::sellerLeads()->show($this->seller(), (string) $code);

        return $l ? $this->ok(['lead' => $l]) : $this->failCode(404, 'not_found', 'Lead not found.');
    }
}

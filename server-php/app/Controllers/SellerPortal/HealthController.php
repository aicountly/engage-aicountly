<?php

namespace App\Controllers\SellerPortal;

/** GET /api/v1/seller-portal/health (portal key only) — lets the BFF show "engage: ok". */
class HealthController extends BaseSellerPortalController
{
    public function show()
    {
        return $this->ok(['service' => 'engage-seller-portal']);
    }
}

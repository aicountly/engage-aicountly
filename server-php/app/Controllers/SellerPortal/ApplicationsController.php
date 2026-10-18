<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * POST /api/v1/seller-portal/applications — "request to become a seller" (portal key only).
 *
 * Body (seller-aicountly docs/ENGAGE_SELLER_API.md): {name, business_name?, entity_type?, email, mobile, city?, state?,
 * sales_approach?, experience?, consent: {contact: true, privacy_policy_url, consented_at}, source: {utm_*, ref, landing_path}}
 * → 201 {application_code, status: "requested"}; an open application for the email → 200 with it;
 *   an existing seller's email → 200 {application_code: null, status: "received"} (the BFF shows "submitted" for all).
 * Creates an Engage lead (source_type seller_request). A filled honeypot (website / hp) is answered like a
 * success and stored nowhere. 5 requests per visitor IP per hour (X-Seller-Client-IP).
 */
class ApplicationsController extends BaseSellerPortalController
{
    private const HONEYPOT_FIELDS = ['website', 'hp', 'honeypot', 'company_website'];

    public function create()
    {
        $in = $this->input();
        if ($limited = $this->throttle('apply:' . $this->clientIp(), 5, HOUR)) {
            return $limited;
        }
        foreach (self::HONEYPOT_FIELDS as $f) {
            if (is_scalar($in[$f] ?? null) && trim((string) $in[$f]) !== '') {
                log_message('info', 'Seller application honeypot filled from ' . $this->clientIp() . '; dropped.');

                return $this->ok(['application_code' => null, 'status' => 'received'], 200);
            }
        }
        $r = Services::sellerApplications()->submit($in, $this->clientIp(), $this->clientUa());
        if (! $r['ok']) {
            return $this->failCode(422, 'validation_failed', 'Please correct the highlighted fields.', $r['errors']);
        }

        return $this->ok($r['data'], $r['status']);
    }
}

<?php

namespace App\Controllers\SellerPortal;

use App\Services\Seller\SellerApplicationService;
use Config\Services;

/**
 * POST /api/v1/seller-portal/applications — "request to become a seller" (no session).
 *
 * Body: {name, email, mobile, organization?, entity_type?, city?, state?, profile?, message?, website (honeypot)}
 * → 201 {received: true, message}. The answer is identical for a new request, a repeat request and a
 * request from someone who is already a seller (no enumeration). A filled honeypot gets the same answer
 * and stores nothing. 5 requests per visitor IP per hour (X-Seller-Client-IP from the BFF).
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
        $received = ['received' => true, 'message' => SellerApplicationService::RECEIVED_MESSAGE];
        foreach (self::HONEYPOT_FIELDS as $f) {
            if (trim((string) ($in[$f] ?? '')) !== '') {
                log_message('info', 'Seller application honeypot filled from ' . $this->clientIp() . '; dropped.');

                return $this->ok($received, 201);
            }
        }
        $r = Services::sellerApplications()->submit($in, $this->clientIp(), $this->clientUa());
        if (! $r['ok']) {
            return $this->failCode(422, 'validation_failed', 'Please correct the highlighted fields.', $r['errors']);
        }

        return $this->ok($received, 201);
    }
}

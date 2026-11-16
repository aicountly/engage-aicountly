<?php

namespace App\Controllers\Inbound;

use App\Controllers\BaseApiController;
use App\Services\Email\DeliveryWebhook;

/**
 * AICOUNTLY Email → Engage: signed delivery events for mail Engage sent through the central delivery API.
 *
 *   POST v1/inbound/email/delivery-events
 *   X-Aicountly-Timestamp, X-Aicountly-Signature: sha256=hex(HMAC_SHA256(AICOUNTLY_EMAIL_WEBHOOK_SECRET, ts + "." + raw body))
 *
 * No route filter: the signature over the raw body is the authentication (Services\Email\DeliveryWebhook).
 */
class EmailDeliveryController extends BaseApiController
{
    public function events()
    {
        $r = (new DeliveryWebhook())->handle(
            (string) $this->request->getBody(),
            $this->request->getHeaderLine('X-Aicountly-Timestamp'),
            $this->request->getHeaderLine('X-Aicountly-Signature'),
        );

        return $this->response->setStatusCode($r['status'])->setJSON($r['body']);
    }
}

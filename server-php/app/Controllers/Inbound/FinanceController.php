<?php

namespace App\Controllers\Inbound;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * finance.aicountly.org → Engage (filter finance-inbound: X-Finance-Key = ENGAGE_INBOUND_FINANCE_KEY).
 *
 *   POST v1/inbound/finance/payout-status
 *   {batch_ref, item_ref, status: approved|on_hold|paid|failed|reversed|cancelled, tds_amount, gst_amount, net_amount,
 *    utr|null, paid_at|null, reason|null, tds_section?}
 *   → 200 {item_ref, status, result: updated|unchanged, entries_paid, entries_returned}
 *   422 validation_failed (paid without utr, bad amounts) · 404 not_found (unknown item_ref) · 409 conflict (transition
 *   not allowed, e.g. a failed payout reported paid). A repeat of a status already applied answers 200 unchanged.
 */
class FinanceController extends BaseApiController
{
    public function payoutStatus()
    {
        $in = $this->request->getJSON(true);
        if (! is_array($in)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'code' => 'validation_failed', 'error' => 'Send a JSON object.']);
        }
        $r = Services::sellerPayouts()->applyFinanceStatus($in);
        $status = (int) $r['status'];
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'], $status);
        }
        $body = ['ok' => false, 'error' => $r['error'] ?? 'Request failed.', 'code' => $r['code'] ?? 'error'];
        if (isset($r['details'])) {
            $body['details'] = $r['details'];
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }
}

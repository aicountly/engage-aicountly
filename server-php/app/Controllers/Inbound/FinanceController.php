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
 *
 *   POST v1/inbound/finance/payment-events
 *   {event_type: payment.succeeded|payment.refunded, source_ref (the order), payment_ref, invoice_no?, invoice_date?,
 *    paid_at?} → 200 {order_ref, status, tokens, duplicate?}
 *   Finance sends this only for a payment it has re-read from the provider and invoiced, so it is the signal that
 *   tokens may be granted. A repeat answers 200 duplicate: Finance retrying is correct, and an error would make it
 *   retry for ever.
 */
class FinanceController extends BaseApiController
{
    /**
     * A payment Finance has verified. For a token order this is what turns money into
     * tokens: the order is marked paid and the grant queued in one transaction, because
     * an order marked paid with no grant is a customer who has been charged and given
     * nothing.
     *
     * Only token orders are handled here today. Anything else is acknowledged and
     * ignored rather than refused — Finance should not retry an event Engage simply has
     * no use for, and a 4xx would look like a fault on the Finance side.
     */
    public function paymentEvents()
    {
        $in = $this->request->getJSON(true);
        if (! is_array($in)) {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'code' => 'validation_failed', 'error' => 'Send a JSON object.']);
        }

        $type = (string) ($in['event_type'] ?? 'payment.succeeded');
        $orderRef = trim((string) ($in['source_ref'] ?? $in['order_ref'] ?? ''));
        if (! str_starts_with($orderRef, 'TKO-')) {
            return $this->ok(['handled' => false, 'reason' => 'not_a_token_order']);
        }
        if ($type !== 'payment.succeeded') {
            // A refund reverses tokens, which is a separate decision: the customer may
            // already have spent them. Recorded, not acted on.
            log_message('info', 'token order {o}: {t} received and not acted on', ['o' => $orderRef, 't' => $type]);

            return $this->ok(['handled' => false, 'reason' => 'event_not_actionable', 'event_type' => $type]);
        }

        $r = (new \App\Services\Tokens\TokenOrderService())->settle($orderRef, [
            'payment_ref'  => (string) ($in['payment_ref'] ?? ''),
            'invoice_no'   => $in['invoice_no'] ?? null,
            'invoice_date' => $in['invoice_date'] ?? null,
            'paid_at'      => $in['paid_at'] ?? null,
        ]);

        return $this->relay($r);
    }

    /** @param array<string,mixed> $r */
    private function relay(array $r)
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'] ?? [], $status);
        }
        $body = ['ok' => false, 'error' => $r['error'] ?? 'Request failed.', 'code' => $r['code'] ?? 'error'];
        if (isset($r['details'])) {
            $body['details'] = $r['details'];
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

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

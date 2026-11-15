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
 *   POST v1/inbound/finance/payment-events      (filter finance-events: AIC-S2S client finance, or X-Finance-Key)
 *   Finance's EngagePaymentEventOutbox body: {event_id, event_type: payment.succeeded|payment.refunded, lead_code (the
 *   order the link was raised for — source_ref / order_ref are accepted too), payment_ref, amount_paise, paid_at, …}
 *   → 200 {…, event_id}. Finance counts an event delivered only when the answer echoes its event_id, so it is echoed
 *   for every event Engage acted on (a repeat answers the same, duplicate: true) and for none it did not.
 *     TKO-…  a token order: payment.succeeded grants the tokens (TokenOrderService::settle)
 *   The amount must be the order's total: anything else is 409 amount_mismatch and nothing is granted.
 *   Any other reference (e.g. an Academy fee on a lead) is acknowledged without event_id: Engage does not act on it.
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
        $eventId = is_string($in['event_id'] ?? null) && $in['event_id'] !== '' ? mb_substr($in['event_id'], 0, 128) : null;
        // Finance names the order its link was raised for in `lead_code` (the source_ref Engage sent).
        $orderRef = trim((string) ($in['source_ref'] ?? $in['order_ref'] ?? $in['lead_code'] ?? ''));
        $amount = isset($in['amount_paise']) && (is_int($in['amount_paise']) || (is_string($in['amount_paise']) && ctype_digit($in['amount_paise'])))
            ? (int) $in['amount_paise'] : null;

        if (! str_starts_with($orderRef, 'TKO-')) {
            // Not an order Engage holds (e.g. an Academy fee on a lead): acknowledged, not acted on, and no event_id —
            // Finance keeps it visible as undelivered rather than believing Engage did something with it.
            return $this->ok(['handled' => false, 'reason' => 'not_an_order']);
        }
        if ($type !== 'payment.succeeded') {
            // A refund reverses tokens, which is a separate decision: the customer may
            // already have spent them. Recorded, not acted on.
            log_message('info', 'token order {o}: {t} received and not acted on', ['o' => $orderRef, 't' => $type]);

            return $this->ok(['handled' => false, 'reason' => 'event_not_actionable', 'event_type' => $type]);
        }
        $tokens = new \App\Services\Tokens\TokenOrderService();
        $order = $tokens->findByRef($orderRef);
        if ($order !== null && $amount !== null && $amount !== (int) $order['total_paise'] && ! in_array($order['status'], ['paid', 'granted'], true)) {
            log_message('critical', 'token order {o}: payment of {a} paise does not match the order total {t}', ['o' => $orderRef, 'a' => $amount, 't' => $order['total_paise']]);

            return $this->relay(['status' => 409, 'code' => 'amount_mismatch', 'error' => 'The amount paid is not the order total. Nothing was granted.',
                'details' => ['expected_paise' => (int) $order['total_paise'], 'received_paise' => $amount]]);
        }

        $r = $tokens->settle($orderRef, [
            'payment_ref'  => (string) ($in['payment_ref'] ?? ''),
            'invoice_no'   => $in['invoice_no'] ?? null,
            'invoice_date' => $in['invoice_date'] ?? null,
            'paid_at'      => $in['paid_at'] ?? null,
        ]);

        return $this->relay($r, $eventId);
    }

    /** @param array<string,mixed> $r */
    private function relay(array $r, ?string $eventId = null)
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            // Finance's receipt check: the event it sent, echoed back.
            return $this->ok(($r['data'] ?? []) + ($eventId !== null ? ['event_id' => $eventId] : []), $status);
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

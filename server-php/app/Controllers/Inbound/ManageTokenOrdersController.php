<?php
declare(strict_types=1);

namespace App\Controllers\Inbound;

use App\Controllers\BaseApiController;
use App\Services\Tokens\FinanceLinkClient;
use App\Services\Tokens\TokenOrderService;
use App\Services\Tokens\TokenVoucherService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * manage.aicountly.com (server side) → Engage: a customer buying AI tokens.
 *
 * AIC-S2S v1, client `manage`, a key of scope `quote` — the same key that already
 * does coupon previews and upgrade requests. Manage's BROWSER never reaches Engage;
 * Manage's server does, having already established who the signed-in person is and
 * which company they are in. Engage trusts that, exactly as it does for the coupon
 * preview, and never takes the holder from the browser.
 *
 *   POST v1/manage/token-orders/quote     price it; creates nothing
 *   POST v1/manage/token-orders           create the order and raise the payment link
 *   GET  v1/manage/token-orders           this holder's orders, newest first
 *   POST v1/manage/token-orders/{ref}/cancel
 *   POST v1/manage/token-vouchers/redeem  redeem a voucher code into the wallet
 *
 * Money moves at Finance, on a page the gateway hosts. Nothing here takes a card
 * number and nothing here grants tokens: a grant happens only when Finance confirms
 * the payment it has re-read from the provider.
 */
class ManageTokenOrdersController extends BaseApiController
{
    private function result(array $r): ResponseInterface
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'] ?? [], $status);
        }
        $body = ['ok' => false, 'error' => (string) ($r['error'] ?? 'Request failed.'), 'code' => (string) ($r['code'] ?? 'error')];
        if (isset($r['details'])) {
            $body['details'] = $r['details'];
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    /** @return array{0:?string, 1:?int, 2:array<string,mixed>} holder, company, body */
    private function who(): array
    {
        $in = $this->request->getJSON(true);
        $in = is_array($in) ? $in : [];
        $holder = trim((string) ($in['holder_uuid'] ?? $this->request->getGet('holder_uuid') ?? ''));
        $cmpId = (int) ($in['cmp_id'] ?? $this->request->getGet('cmp_id') ?? 0);

        return [$holder !== '' ? $holder : null, $cmpId > 0 ? $cmpId : null, $in];
    }

    public function quote(): ResponseInterface
    {
        [$holder, , $in] = $this->who();

        return $this->result((new TokenOrderService())->quote($in, $holder));
    }

    /**
     * Create the order and raise the link in one call.
     *
     * One call rather than two because a customer who has pressed Buy expects to
     * arrive at a payment page, and an order with no link is a dead end they cannot
     * see. If Finance cannot raise one the order stays `quoted` and the answer says
     * why — nothing has been charged and they can try again.
     */
    public function create(): ResponseInterface
    {
        [$holder, $cmpId, $in] = $this->who();
        if ($holder === null || $cmpId === null) {
            return $this->result(['status' => 422, 'code' => 'validation_failed', 'error' => 'holder_uuid and cmp_id are required.']);
        }

        $orders = new TokenOrderService();
        $created = $orders->create($in, $holder, $cmpId, is_array($in['payer'] ?? null) ? $in['payer'] : [], 'manage');
        if (($created['status'] ?? 500) >= 300) {
            return $this->result($created);
        }

        $order = $created['data'];
        if (($order['link_url'] ?? null) !== null) {
            // An order that already had a link (a reload, a second click): send them
            // back to the same one rather than making another.
            return $this->result(['status' => 200, 'data' => $order]);
        }

        $row = $orders->db()->table('engage_token_orders')->where('order_ref', $order['order_ref'])->get()->getRowArray();
        $link = (new FinanceLinkClient())->raise((string) $order['order_ref'], $orders->financeRequest($row));
        if (! $link['ok']) {
            return $this->result([
                'status' => $link['code'] === 'not_configured' ? 503 : 502,
                'code'   => 'payment_link_failed',
                'error'  => $link['message'] ?? 'The payment page could not be opened. Nothing has been charged.',
                'details' => ['order_ref' => $order['order_ref'], 'finance_code' => $link['code']],
            ]);
        }

        return $this->result($orders->linkRaised((int) $order['id'], $link['link']));
    }

    public function index(): ResponseInterface
    {
        [$holder] = $this->who();
        if ($holder === null) {
            return $this->result(['status' => 422, 'code' => 'validation_failed', 'error' => 'holder_uuid is required.']);
        }

        return $this->ok(['items' => (new TokenOrderService())->forHolder($holder, (int) ($this->request->getGet('limit') ?? 50))]);
    }

    public function cancel($ref = null): ResponseInterface
    {
        [$holder, , $in] = $this->who();
        $orders = new TokenOrderService();
        $o = $orders->findByRef((string) $ref);
        // A holder may only cancel their own order, and a stranger learns nothing
        // about whether it exists.
        if ($o === null || $holder === null || $o['holder_uuid'] !== $holder) {
            return $this->result(['status' => 404, 'code' => 'not_found', 'error' => 'No such token order.']);
        }

        return $this->result($orders->cancel((string) $ref, trim((string) ($in['reason'] ?? 'Cancelled by the customer.'))));
    }

    /**
     * Redeem a voucher code. The tokens are queued, not granted here, so the answer
     * says `queued` rather than implying the balance has already moved.
     */
    public function redeemVoucher(): ResponseInterface
    {
        [$holder, $cmpId, $in] = $this->who();
        if ($holder === null) {
            return $this->result(['status' => 422, 'code' => 'validation_failed', 'error' => 'holder_uuid is required.']);
        }
        $want = isset($in['tokens']) && $in['tokens'] !== '' ? (int) $in['tokens'] : null;

        return $this->result((new TokenVoucherService())->redeem((string) ($in['code'] ?? ''), $holder, $cmpId, $want, 'manage'));
    }
}

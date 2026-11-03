<?php
declare(strict_types=1);

namespace App\Controllers\Inbound;

use App\Libraries\AicS2s\AicS2sResponse;
use App\Services\Tokens\FinanceLinkClient;
use App\Services\Tokens\TokenOrderService;
use App\Services\Tokens\TokenVoucherService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * manage.aicountly.com (server side) → Engage: a customer buying AI tokens.
 *
 * AIC-S2S v1, client `manage`, a key of scope `quote` — the same key that already does
 * coupon previews and upgrade requests. Manage's BROWSER never reaches Engage; Manage's
 * server does, having already established who the signed-in person is and which company
 * they are in. Engage trusts that, exactly as it does for the coupon preview, and never
 * takes the holder from the browser.
 *
 *   POST v1/manage/token-orders/quote     price it; creates nothing
 *   POST v1/manage/token-orders           create the order and raise the payment link
 *   GET  v1/manage/token-orders           this holder's orders, newest first (READ key: the quote
 *                                         scope is POST-only, and reading is a read)
 *   POST v1/manage/token-orders/{ref}/cancel
 *   POST v1/manage/token-vouchers/redeem  redeem a voucher code into the wallet
 *
 * THE ENVELOPE IS THE CONTRACT. Every answer goes out through AicS2sResponse —
 * `{success, data, request_id}` or `{success:false, error:{code, message, details,
 * retryable}}` — because that is what Manage's S2sClient reads. A route that answers in
 * Engage's own internal `{ok:true}` shape is read by Manage as "not the AIC-S2S success
 * envelope" and reported to the customer as Engage being down, whatever it actually said.
 * The scope is checked here too: holding a `read` key must not let a caller start a payment.
 *
 * Money moves at Finance, on a page the gateway hosts. Nothing here takes a card number and
 * nothing here grants tokens: a grant happens only when Finance confirms the payment it has
 * re-read from the provider.
 */
class ManageTokenOrdersController extends Controller
{
    /** The request id this answer carries, and the verified S2S context. */
    private function context(): array
    {
        $ctx = $this->request->aicS2s ?? [];

        return [$ctx, (string) ($ctx['request_id'] ?? AicS2sResponse::requestId(''))];
    }

    /**
     * Run one route: check the key's scope, read the body, and put the service's answer into
     * the envelope Manage reads.
     *
     * @param callable(array<string,mixed>, ?string, ?int): array $fn body, holder, company
     * @param list<string> $scopes the key scopes this route accepts
     */
    private function run(callable $fn, bool $needsHolder = true, bool $needsCompany = false, array $scopes = ['quote']): ResponseInterface
    {
        [$ctx, $rid] = $this->context();
        if (! in_array((string) ($ctx['scope'] ?? ''), $scopes, true)) {
            return AicS2sResponse::error($this->response, 403, 'route_not_allowed_for_key',
                'This key may not call this route.', $rid, false, ['scope' => $ctx['scope'] ?? null]);
        }

        $in = json_decode((string) $this->request->getBody(), true);
        $in = is_array($in) && !(array_is_list($in) && $in !== []) ? $in : [];
        $holder = trim((string) ($in['holder_uuid'] ?? $this->request->getGet('holder_uuid') ?? ''));
        $cmpId = (int) ($in['cmp_id'] ?? $this->request->getGet('cmp_id') ?? 0);
        $holder = $holder !== '' ? $holder : null;
        $cmpId = $cmpId > 0 ? $cmpId : null;

        if ($needsHolder && $holder === null) {
            return AicS2sResponse::error($this->response, 400, 'bad_request', 'holder_uuid is required.', $rid, false, ['field' => 'holder_uuid']);
        }
        if ($needsCompany && $cmpId === null) {
            return AicS2sResponse::error($this->response, 400, 'bad_request', 'cmp_id is required.', $rid, false, ['field' => 'cmp_id']);
        }

        try {
            $r = $fn($in, $holder, $cmpId);
        } catch (\Throwable $e) {
            log_message('error', 'Manage token route failed: ' . $e->getMessage());

            return AicS2sResponse::error($this->response, 503, 'database_unavailable', 'Engage is temporarily unavailable.', $rid, true);
        }

        $status = (int) ($r['status'] ?? 500);
        if ($status < 300) {
            return AicS2sResponse::success($this->response, $r['data'] ?? [], null, $rid, $status);
        }

        return AicS2sResponse::error($this->response, $status, (string) ($r['code'] ?? 'error'),
            (string) ($r['error'] ?? 'Request failed.'), $rid,
            (bool) ($r['retryable'] ?? ($status >= 500 || $status === 429)), (array) ($r['details'] ?? []));
    }

    public function quote(): ResponseInterface
    {
        return $this->run(static fn (array $in, ?string $holder): array => (new TokenOrderService())->quote($in, $holder));
    }

    /**
     * Create the order and raise the link in one call.
     *
     * One call rather than two because a customer who has pressed Buy expects to arrive at a
     * payment page, and an order with no link is a dead end they cannot see. If Finance cannot
     * raise one the order stays `quoted` and the answer says why — nothing has been charged and
     * they can try again, and the order shows in their list meanwhile.
     */
    public function create(): ResponseInterface
    {
        return $this->run(static function (array $in, ?string $holder, ?int $cmpId): array {
            $orders = new TokenOrderService();
            $created = $orders->create($in, (string) $holder, (int) $cmpId, is_array($in['payer'] ?? null) ? $in['payer'] : [], 'manage');
            if (($created['status'] ?? 500) >= 300) {
                return $created;
            }

            $order = $created['data'];
            if (($order['link_url'] ?? null) !== null) {
                // An order that already had a link (a reload, a second click): send them back
                // to the same one rather than making another.
                return ['status' => 200, 'data' => $order];
            }

            $row = $orders->db()->table('engage_token_orders')->where('order_ref', $order['order_ref'])->get()->getRowArray();
            $link = (new FinanceLinkClient())->raise((string) $order['order_ref'], $orders->financeRequest($row));
            if (! $link['ok']) {
                return [
                    'status'    => $link['code'] === 'not_configured' ? 503 : 502,
                    'code'      => 'payment_link_failed',
                    'error'     => $link['message'] ?? 'The payment page could not be opened. Nothing has been charged.',
                    'retryable' => true,
                    'details'   => ['order_ref' => $order['order_ref'], 'finance_code' => $link['code']],
                ];
            }

            return $orders->linkRaised((int) $order['id'], $link['link']);
        }, true, true);
    }

    /** The holder's purchases. A read, on the read key — see Routes.php. */
    public function index(): ResponseInterface
    {
        return $this->run(fn (array $in, ?string $holder): array => [
            'status' => 200,
            'data'   => ['items' => (new TokenOrderService())->forHolder((string) $holder, (int) ($this->request->getGet('limit') ?? 50))],
        ], true, false, ['read']);
    }

    public function cancel($ref = null): ResponseInterface
    {
        return $this->run(static function (array $in, ?string $holder) use ($ref): array {
            $orders = new TokenOrderService();
            $o = $orders->findByRef((string) $ref);
            // A holder may only cancel their own order, and a stranger learns nothing about
            // whether it exists.
            if ($o === null || $o['holder_uuid'] !== $holder) {
                return ['status' => 404, 'code' => 'not_found', 'error' => 'No such token order.'];
            }

            return $orders->cancel((string) $ref, trim((string) ($in['reason'] ?? 'Cancelled by the customer.')));
        });
    }

    /**
     * Redeem a voucher code. The tokens are queued, not granted here, so the answer says
     * `queued` rather than implying the balance has already moved.
     */
    public function redeemVoucher(): ResponseInterface
    {
        return $this->run(static function (array $in, ?string $holder, ?int $cmpId): array {
            $want = isset($in['tokens']) && $in['tokens'] !== '' ? (int) $in['tokens'] : null;

            return (new TokenVoucherService())->redeem((string) ($in['code'] ?? ''), (string) $holder, $cmpId, $want, 'manage');
        });
    }
}

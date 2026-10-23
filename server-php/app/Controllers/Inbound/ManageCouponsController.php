<?php
declare(strict_types=1);

namespace App\Controllers\Inbound;

use App\Libraries\AicS2s\AicS2sResponse;
use App\Services\Coupons\ManageCouponService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * manage.aicountly.com (server side) → Engage coupons (contract §4.1, docs/COUPONS.md §7). Filter `aic-s2s:manage`;
 * only a key of scope `quote` may call these two routes (the filter refuses every other path for such a key, and these
 * routes refuse read / issue keys with the same 403 route_not_allowed_for_key).
 *
 *   POST v1/manage/coupons/preview     {holder_uuid, offer_code, code}
 *   POST v1/manage/upgrade-requests    {holder_uuid, cmp_id?, offer_code, coupon_code?, contact:{name,email,phone}, request_id?}
 */
class ManageCouponsController extends Controller
{
    public function preview()
    {
        return $this->run(static fn (ManageCouponService $s, array $in, array $ctx) => $s->preview($in, $ctx));
    }

    public function upgradeRequest()
    {
        return $this->run(static fn (ManageCouponService $s, array $in, array $ctx) => $s->upgradeRequest($in, $ctx));
    }

    /** @param callable(ManageCouponService, array, array): array $fn */
    private function run(callable $fn): ResponseInterface
    {
        $ctx = $this->request->aicS2s ?? [];
        $rid = (string) ($ctx['request_id'] ?? AicS2sResponse::requestId(''));
        if (($ctx['scope'] ?? null) !== 'quote') {
            return AicS2sResponse::error($this->response, 403, 'route_not_allowed_for_key', 'This key may not call this route.', $rid, false, ['scope' => $ctx['scope'] ?? null]);
        }
        $in = json_decode((string) $this->request->getBody(), true);
        if (! is_array($in) || array_is_list($in) && $in !== []) {
            return AicS2sResponse::error($this->response, 400, 'bad_request', 'The body must be a JSON object.', $rid, false, ['field' => 'body']);
        }
        try {
            $r = $fn(new ManageCouponService(), $in, $ctx + ['ip' => $this->request->getIPAddress()]);
        } catch (\Throwable $e) {
            log_message('error', 'Manage coupon route failed: ' . $e->getMessage());

            return AicS2sResponse::error($this->response, 503, 'database_unavailable', 'Engage is temporarily unavailable.', $rid, true);
        }
        if ($r['status'] < 300) {
            return AicS2sResponse::success($this->response, $r['data'], null, $rid, $r['status']);
        }
        $res = AicS2sResponse::error($this->response, $r['status'], (string) $r['code'], (string) $r['error'], $rid, (bool) ($r['retryable'] ?? $r['status'] >= 500 || $r['status'] === 429), (array) ($r['details'] ?? []));
        if (isset($r['retry_after'])) {
            $res->setHeader('Retry-After', (string) $r['retry_after']);
        }

        return $res;
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Coupons;

use App\Controllers\BaseApiController;
use App\Services\Coupons\CouponService;
use App\Services\Coupons\QuoteService;
use App\Services\Coupons\RedemptionService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff coupons (docs/COUPONS.md). Role filters in Routes.php:
 *   view   super_admin | sales_manager | seller_manager   GET coupons, coupons/{id}, coupons/{id}/codes, codes.csv (export),
 *                                                         coupon-redemptions, coupon-report, quotes
 *   admin  super_admin | sales_manager                    POST/PUT/DELETE coupons…, activate | pause | retire, codes (generate),
 *                                                         codes/{id}/void, quotes, coupon-redemptions/{id}/release | reverse
 */
class CouponsController extends BaseApiController
{
    private function result(array $r): ResponseInterface
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'] ?? [], $status);
        }
        $body = ['ok' => false, 'error' => (string) ($r['error'] ?? 'Request failed.')];
        foreach (['code', 'details'] as $k) {
            if (isset($r[$k])) {
                $body[$k] = $r[$k];
            }
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    private function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }

    public function index()
    {
        return $this->ok(['items' => (new CouponService())->list($this->query())]);
    }

    public function show($id = null)
    {
        $c = (new CouponService())->detail((int) $id);

        return $c ? $this->ok($c) : $this->fail('Coupon not found.', 404);
    }

    public function create()
    {
        return $this->result((new CouponService())->createDraft($this->input(), $this->user()));
    }

    public function update($id = null)
    {
        return $this->result((new CouponService())->updateDraft((int) $id, $this->input(), $this->user()));
    }

    public function delete($id = null)
    {
        return $this->result((new CouponService())->discardDraft((int) $id, $this->user()));
    }

    public function activate($id = null)
    {
        return $this->result((new CouponService())->activate((int) $id, $this->input(), $this->user()));
    }

    public function pause($id = null)
    {
        return $this->result((new CouponService())->pause((int) $id, $this->input(), $this->user()));
    }

    public function retire($id = null)
    {
        return $this->result((new CouponService())->retire((int) $id, $this->input(), $this->user()));
    }

    public function codes($id = null)
    {
        if (! (new CouponService())->find((int) $id)) {
            return $this->fail('Coupon not found.', 404);
        }

        return $this->ok((new CouponService())->codes((int) $id, $this->query()));
    }

    public function addCodes($id = null)
    {
        return $this->result((new CouponService())->addCodes((int) $id, $this->input(), $this->user()));
    }

    public function voidCode($id = null, $codeId = null)
    {
        return $this->result((new CouponService())->voidCode((int) $id, (int) $codeId, $this->user()));
    }

    /** CSV download of codes for distribution (audited). */
    public function exportCodes($id = null)
    {
        $r = (new CouponService())->exportCodes((int) $id, $this->query(), $this->user());
        if ($r['status'] !== 200) {
            return $this->result($r);
        }

        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $r['filename'] . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($r['csv']);
    }

    public function redemptions()
    {
        return $this->ok(['items' => (new RedemptionService())->list($this->query())]);
    }

    public function release($id = null)
    {
        return $this->result((new RedemptionService())->release((int) $id, (string) ($this->input()['reason'] ?? ''), $this->user()));
    }

    public function reverse($id = null)
    {
        return $this->result((new RedemptionService())->reverse((int) $id, (string) ($this->input()['reason'] ?? ''), $this->user()));
    }

    public function report()
    {
        return $this->ok((new CouponService())->report($this->query()));
    }

    // ------------------------------------------------------------------ quotes

    public function createQuote()
    {
        $user = $this->user();

        return $this->result((new QuoteService())->create($this->input(), [
            'source' => 'staff', 'staff_id' => (int) $user['id'], 'channel' => 'staff_quote', 'scope_key' => 'staff:' . (int) $user['id'],
            'ip' => $this->request->getIPAddress(),
        ]));
    }

    public function quotes()
    {
        return $this->ok(['items' => (new QuoteService())->list($this->query())]);
    }

    public function quote($ref = null)
    {
        $q = is_string($ref) ? (new QuoteService())->findBy('quote_ref', strtoupper($ref)) : null;

        return $q ? $this->ok((new QuoteService())->view($q)) : $this->fail('Quote not found.', 404);
    }
}

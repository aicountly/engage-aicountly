<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use App\Libraries\Seller\Money;
use App\Services\SellerLicences\SellerLicenceApplyService;
use App\Services\SellerLicences\SellerLicenceOrderService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Staff: licences sellers bought (docs/SELLING_LICENCES.md §7). View: super_admin | sales_manager | seller_manager;
 * confirm payment, cancel, withdraw a licence: super_admin | sales_manager (Routes.php).
 *
 *   GET  v1/seller-licence-orders ?status=&seller_id=&q=&page=&limit=
 *   GET  v1/seller-licence-orders/{order_ref}                   the order, its lines and every licence unit
 *   POST v1/seller-licence-orders/{order_ref}/confirm-payment   {payment_ref, amount ("11800.00"), invoice_no, invoice_date?, paid_at?}
 *                                                               an offline payment Finance has invoiced: paid + stock created
 *   POST v1/seller-licence-orders/{order_ref}/cancel            {reason} while unpaid
 *   GET  v1/seller-licence-units ?seller_id=&status=&page=&limit=
 *   POST v1/seller-licence-units/{unit_ref}/void                {reason} an unused licence taken out of stock (e.g. refunded)
 */
class SellerLicenceOrdersController extends BaseApiController
{
    private function svc(): SellerLicenceOrderService
    {
        return new SellerLicenceOrderService();
    }

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

    public function index()
    {
        [$page, $limit, $offset] = $this->paging();
        $r = $this->svc()->listForStaff((array) $this->request->getGet(), min($limit, 100), $offset);

        return $this->ok($r + ['page' => $page, 'limit' => min($limit, 100)]);
    }

    public function show($ref = null)
    {
        $o = $this->svc()->byRef((string) $ref);
        if (! $o) {
            return $this->fail('Licence order not found.', 404);
        }
        $units = Database::connect()->query('SELECT u.*, l.label FROM engage_seller_licence_units u JOIN engage_seller_licence_order_lines l ON l.id = u.line_id
            WHERE u.order_id = ? ORDER BY u.id', [(int) $o['id']])->getResultArray();

        return $this->ok($this->svc()->staffView($o) + ['unit_list' => array_map(static fn (array $u): array => SellerLicenceApplyService::unitView($u) + [
            'customer_email' => $u['customer_email'], 'holder_uuid' => $u['holder_uuid'], 'lead_id' => $u['lead_id'] !== null ? (int) $u['lead_id'] : null,
        ], $units)]);
    }

    public function confirmPayment($ref = null)
    {
        $in = $this->input();
        $amount = null;
        try {
            $amount = isset($in['amount_paise']) && (is_int($in['amount_paise']) || ctype_digit((string) $in['amount_paise']))
                ? (int) $in['amount_paise'] : (isset($in['amount']) && $in['amount'] !== '' ? Money::toPaise($in['amount']) : null);
        } catch (\InvalidArgumentException) {
            return $this->result(['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the payment.', 'details' => ['amount' => 'An INR amount like "11800.00".']]);
        }

        return $this->result($this->svc()->settle((string) $ref, [
            'payment_ref' => (string) ($in['payment_ref'] ?? ''), 'amount_paise' => $amount, 'invoice_no' => $in['invoice_no'] ?? null,
            'invoice_date' => $in['invoice_date'] ?? null, 'paid_at' => $in['paid_at'] ?? null,
        ], 'staff', $this->user()));
    }

    public function cancel($ref = null)
    {
        return $this->result($this->svc()->cancel((string) $ref, (string) ($this->input()['reason'] ?? ''), 'staff', null, $this->user()));
    }

    public function units()
    {
        [$page, $limit, $offset] = $this->paging();
        $limit = min($limit, 200);
        $q = (array) $this->request->getGet();
        $where = [];
        $binds = [];
        if (! empty($q['seller_id']) && ctype_digit((string) $q['seller_id'])) {
            $where[] = 'u.seller_id = ?';
            $binds[] = (int) $q['seller_id'];
        }
        if (! empty($q['status']) && in_array($q['status'], ['available', 'applied', 'void'], true)) {
            $where[] = 'u.status = ?';
            $binds[] = $q['status'];
        }
        $db = Database::connect();
        $sql = 'FROM engage_seller_licence_units u JOIN engage_seller_licence_order_lines l ON l.id = u.line_id JOIN engage_seller_licence_orders o ON o.id = u.order_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $db->query('SELECT COUNT(*) AS n ' . $sql, $binds)->getRow()->n;
        $rows = $db->query('SELECT u.*, l.label, o.order_ref ' . $sql . ' ORDER BY u.id DESC LIMIT ? OFFSET ?', array_merge($binds, [$limit, $offset]))->getResultArray();

        return $this->ok(['items' => array_map(static fn (array $u): array => SellerLicenceApplyService::unitView($u) + [
            'seller_id' => (int) $u['seller_id'], 'customer_email' => $u['customer_email'],
        ], $rows), 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function voidUnit($ref = null)
    {
        return $this->result($this->svc()->voidUnit((string) $ref, (string) ($this->input()['reason'] ?? ''), $this->user()));
    }
}

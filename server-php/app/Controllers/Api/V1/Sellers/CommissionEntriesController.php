<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * Staff: commission entries and confirmed payments.
 *   GET  v1/commission-entries ?seller_id= &status= &lead_id=
 *   POST v1/commission-entries/{id}/reverse {reason}               (super_admin | sales_manager)
 *   GET  v1/leads/{id}/payments
 *   POST v1/leads/{id}/payments {amount "1234.50", payment_ref, paid_at?, product_code?, net_amount?, quote_ref?, notes?}   "payment confirmed"
 *   POST v1/lead-payments/{id}/refund {reason}                      reverses accrued / approved entries
 *   GET  v1/lead-payments/by-product ?category= &product_code=a,b &from= &to=   revenue per product (super_admin | sales_manager)
 *   POST v1/leads/{id}/seller {seller_code|null}                    staff (re)attribution
 */
class CommissionEntriesController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->paging();

        return $this->ok(Services::commissions()->listEntries((array) $this->request->getGet(), $limit, $offset) + ['page' => $page, 'limit' => $limit]);
    }

    public function reverse($id = null)
    {
        return $this->respondWith(Services::commissions()->reverse((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function payments($leadId = null)
    {
        if (! (new \App\Models\LeadsModel())->find((int) $leadId)) {
            return $this->fail('Lead not found.', 404);
        }
        $svc = Services::commissions();
        $entries = $svc->listEntries(['lead_id' => (int) $leadId], 200, 0)['items'];

        return $this->ok(['items' => $svc->payments((int) $leadId), 'entries' => $entries]);
    }

    public function confirmPayment($leadId = null)
    {
        return $this->respondWith(Services::commissions()->confirmPayment((int) $leadId, $this->input(), $this->user()));
    }

    public function revenueByProduct()
    {
        return $this->respondWith(Services::commissions()->revenueByProduct((array) $this->request->getGet()));
    }

    public function refund($paymentId = null)
    {
        return $this->respondWith(Services::commissions()->refundPayment((int) $paymentId, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function attribute($leadId = null)
    {
        $leads = new \App\Models\LeadsModel();
        $lead = $leads->find((int) $leadId);
        if (! $lead) {
            return $this->fail('Lead not found.', 404);
        }
        $code = trim((string) ($this->input()['seller_code'] ?? ''));
        $reason = trim((string) ($this->input()['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            return $this->fail('A reason is required.', 422, ['reason' => 'Give a reason.']);
        }
        if ($code === '') {
            $leads->update((int) $leadId, ['seller_id' => null, 'seller_attribution' => null, 'seller_attributed_at' => null, 'seller_protection_expires_at' => null]);
        } else {
            $seller = Services::sellerAccounts()->findByCode($code);
            if (! $seller || $seller['status'] === 'terminated') {
                return $this->fail('No such seller (or the seller is terminated).', 422, ['seller_code' => 'Unknown seller code.']);
            }
            $days = \App\Services\Seller\SellerLeadService::protectionDays();
            $leads->update((int) $leadId, ['seller_id' => (int) $seller['id'], 'seller_attribution' => 'staff',
                'seller_attributed_at' => \App\Libraries\Seller\Clock::now(), 'seller_protection_expires_at' => \App\Libraries\Seller\Clock::at(time() + $days * 86400)]);
        }
        (new \App\Models\LeadActivitiesModel())->record((int) $leadId, 'seller_attribution', $code === '' ? 'Seller attribution removed' : "Attributed to seller {$code}", $reason);
        $this->audit('seller.lead_attribution_changed', ['subject_kind' => 'lead', 'subject_id' => (int) $leadId,
            'metadata' => ['from_seller_id' => $lead['seller_id'], 'to' => $code ?: null, 'reason' => $reason], 'fanout_console' => true]);

        return $this->ok($leads->find((int) $leadId));
    }

    public function outboxStatus()
    {
        $o = Services::reachOutcomes();
        $recent = Services::sellerAccounts()->db()->query(
            'SELECT id, event_id, lead_id, stage, status, attempts, last_http_status, last_error, created_at, delivered_at, next_attempt_at
               FROM engage_reach_outcome_outbox ORDER BY id DESC LIMIT 50'
        )->getResultArray();

        return $this->ok(['configured' => $o->isConfigured(), 'status' => $o->status(), 'counts' => $o->summary(), 'recent' => $recent]);
    }
}

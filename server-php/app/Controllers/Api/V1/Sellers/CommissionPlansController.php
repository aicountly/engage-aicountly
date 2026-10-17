<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * Staff: commission plans (read: seller roles; write / publish / retire: super_admin | sales_manager).
 *   GET  v1/commission-plans ?status=   ·  GET v1/commission-plans/{id}
 *   POST v1/commission-plans {plan_code, name, description?, rules, product_scope?, hold_days?}   → draft v1
 *   PUT  v1/commission-plans/{id}  (draft only)
 *   POST v1/commission-plans/{id}/publish | retire | new-version
 */
class CommissionPlansController extends BaseApiController
{
    public function index()
    {
        $plans = Services::commissions()->listPlans((string) ($this->request->getGet('status') ?? ''));

        return $this->ok(['items' => $plans, 'active_count' => count(array_filter($plans, static fn ($p) => $p['status'] === 'active'))]);
    }

    public function show($id = null)
    {
        $p = Services::commissions()->findPlan((int) $id);

        return $p ? $this->ok(\App\Services\Seller\CommissionService::planView($p)) : $this->fail('Plan not found.', 404);
    }

    public function create()
    {
        return $this->respondWith(Services::commissions()->createDraft($this->input(), $this->user()));
    }

    public function update($id = null)
    {
        return $this->respondWith(Services::commissions()->updateDraft((int) $id, $this->input(), $this->user()));
    }

    public function publish($id = null)
    {
        return $this->respondWith(Services::commissions()->publish((int) $id, $this->user()));
    }

    public function retire($id = null)
    {
        return $this->respondWith(Services::commissions()->retire((int) $id, $this->user()));
    }

    public function newVersion($id = null)
    {
        return $this->respondWith(Services::commissions()->newVersion((int) $id, $this->user()));
    }
}

<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SubscriptionInquiriesModel;

class SubscriptionInquiriesController extends BaseApiController
{
    private SubscriptionInquiriesModel $m;

    public function __construct()
    {
        $this->m = new SubscriptionInquiriesModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['lead_id']))    { $qb->where('lead_id', (int) $q['lead_id']); }
        if (! empty($q['account_id'])) { $qb->where('account_id', (int) $q['account_id']); }
        if (! empty($q['status']))     { $qb->where('status', $q['status']); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Subscription inquiry not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('subscription_inquiry_create', ['subject_kind' => 'subscription_inquiry', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('subscription_inquiry_update', ['subject_kind' => 'subscription_inquiry', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('subscription_inquiry_delete', ['subject_kind' => 'subscription_inquiry', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

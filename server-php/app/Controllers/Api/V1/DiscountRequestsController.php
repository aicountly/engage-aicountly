<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\DiscountRequestsModel;
use Config\Services;

class DiscountRequestsController extends BaseApiController
{
    private DiscountRequestsModel $m;

    public function __construct()
    {
        $this->m = new DiscountRequestsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['lead_id']))     { $qb->where('lead_id', (int) $q['lead_id']); }
        if (! empty($q['proposal_id'])) { $qb->where('proposal_id', (int) $q['proposal_id']); }
        if (! empty($q['status']))      { $qb->where('status', $q['status']); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Discount request not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        $data['requested_by']   = $this->user()['id'] ?? null;
        $data['requester_kind'] = $data['requester_kind'] ?? 'user';
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);

        // Route to approval workflow.
        $approvalId = Services::approvalService()->request([
            'kind'          => 'discount',
            'subject_kind'  => 'discount_request',
            'subject_id'    => (string) $id,
            'risk_level'    => 'high',
            'payload'       => [
                'discount_percent' => $data['discount_percent'] ?? null,
                'discount_amount'  => $data['discount_amount']  ?? null,
                'lead_id'          => $data['lead_id']          ?? null,
                'proposal_id'      => $data['proposal_id']      ?? null,
                'justification'    => $data['justification']    ?? null,
            ],
        ]);
        if ($approvalId) {
            $this->m->update($id, ['approval_id' => $approvalId]);
        }

        $this->audit('discount_request_create', ['subject_kind' => 'discount_request', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $this->m->update((int) $id, $this->input());
        $this->audit('discount_request_update', ['subject_kind' => 'discount_request', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('discount_request_delete', ['subject_kind' => 'discount_request', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }

    public function approve($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Discount request not found.', 404);
        $body = $this->input();
        $this->m->update((int) $id, [
            'status'         => 'approved',
            'decided_by'     => $this->user()['id'] ?? null,
            'decided_at'     => date('Y-m-d H:i:s'),
            'decision_notes' => $body['notes'] ?? null,
        ]);
        $this->audit('discount.applied', [
            'subject_kind' => 'discount_request', 'subject_id' => $id,
            'metadata'     => ['discount_percent' => $row['discount_percent'], 'discount_amount' => $row['discount_amount']],
        ]);
        return $this->ok($this->m->find((int) $id));
    }

    public function reject($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Discount request not found.', 404);
        $body = $this->input();
        $this->m->update((int) $id, [
            'status'         => 'rejected',
            'decided_by'     => $this->user()['id'] ?? null,
            'decided_at'     => date('Y-m-d H:i:s'),
            'decision_notes' => $body['notes'] ?? null,
        ]);
        $this->audit('discount_request_reject', ['subject_kind' => 'discount_request', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }
}

<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalRequestsModel;
use Config\Services;

class ApprovalsController extends BaseApiController
{
    private ApprovalRequestsModel $m;

    public function __construct()
    {
        $this->m = new ApprovalRequestsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['kind']))         { $qb->where('kind', $q['kind']); }
        if (! empty($q['status']))       { $qb->where('status', $q['status']); }
        if (! empty($q['subject_kind'])) { $qb->where('subject_kind', $q['subject_kind']); }
        if (! empty($q['subject_id']))   { $qb->where('subject_id',   (string) $q['subject_id']); }
        if (! empty($q['risk_level']))   { $qb->where('risk_level',   $q['risk_level']); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Approval request not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['kind'])) return $this->fail('kind is required.', 400);
        $id = Services::approvalService()->request($data);
        return $this->ok($this->m->find((int) $id), 201);
    }

    public function update($id = null)
    {
        $this->m->update((int) $id, $this->input());
        $this->audit('approval_update', ['subject_kind' => 'approval_request', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        return $this->ok(['deleted' => true]);
    }

    public function approve($id = null)
    {
        $body = $this->input();
        $row  = Services::approvalService()->approve(
            (int) $id,
            $this->user()['id'] ?? null,
            $body['notes'] ?? null,
        );
        if (! $row) return $this->fail('Approval request not found.', 404);
        return $this->ok($row);
    }

    public function reject($id = null)
    {
        $body = $this->input();
        $row  = Services::approvalService()->reject(
            (int) $id,
            $this->user()['id'] ?? null,
            $body['notes'] ?? null,
        );
        if (! $row) return $this->fail('Approval request not found.', 404);
        return $this->ok($row);
    }

    public function execute($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Approval request not found.', 404);
        if ($row['status'] !== 'approved' && $row['status'] !== 'auto_approved') {
            return $this->fail('Not approved yet.', 409, ['status' => $row['status']]);
        }
        $body = $this->input();
        $result = Services::approvalService()->executed((int) $id, (array) ($body['result'] ?? []));
        $this->audit('approval.executed', [
            'subject_kind' => 'approval_request', 'subject_id' => $id,
            'metadata'     => ['kind' => $row['kind']],
            'fanout_console' => true,
        ]);
        return $this->ok($result);
    }
}

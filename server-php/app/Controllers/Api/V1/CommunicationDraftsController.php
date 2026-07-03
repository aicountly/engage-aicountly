<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\CommunicationDraftsModel;
use Config\Services;

class CommunicationDraftsController extends BaseApiController
{
    private CommunicationDraftsModel $m;

    public function __construct()
    {
        $this->m = new CommunicationDraftsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['lead_id'])) { $qb->where('lead_id', (int) $q['lead_id']); }
        if (! empty($q['channel'])) { $qb->where('channel', $q['channel']); }
        if (! empty($q['status']))  { $qb->where('status', $q['status']); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Draft not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['channel'])) return $this->fail('channel is required.', 400);
        $data['created_by']   = $this->user()['id'] ?? null;
        $data['creator_kind'] = $data['creator_kind'] ?? 'user';
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            $data['attachments'] = json_encode($data['attachments']);
        }
        $id = $this->m->insert($data, true);

        // Sending external messages always needs approval.
        $approvalId = Services::approvalService()->request([
            'kind'          => 'communication',
            'subject_kind'  => 'communication_draft',
            'subject_id'    => (string) $id,
            'risk_level'    => 'medium',
            'payload'       => [
                'channel'    => $data['channel'],
                'to_address' => $data['to_address'] ?? null,
                'subject'    => $data['subject']    ?? null,
                'lead_id'    => $data['lead_id']    ?? null,
            ],
        ]);
        if ($approvalId) {
            $this->m->update($id, ['approval_id' => $approvalId, 'status' => 'awaiting_approval']);
        }

        $this->audit('communication_draft_create', ['subject_kind' => 'communication_draft', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            $data['attachments'] = json_encode($data['attachments']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('communication_draft_update', ['subject_kind' => 'communication_draft', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('communication_draft_delete', ['subject_kind' => 'communication_draft', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }

    public function approve($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Draft not found.', 404);
        $this->m->update((int) $id, [
            'status'      => 'approved',
            'approved_by' => $this->user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('communication_draft_approve', ['subject_kind' => 'communication_draft', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function reject($id = null)
    {
        $this->m->update((int) $id, [
            'status' => 'rejected',
        ]);
        $this->audit('communication_draft_reject', ['subject_kind' => 'communication_draft', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }
}

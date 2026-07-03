<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\FollowUpsModel;

class FollowUpsController extends BaseApiController
{
    private FollowUpsModel $m;

    public function __construct()
    {
        $this->m = new FollowUpsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('due_at', 'ASC');
        if (! empty($q['lead_id']))  { $qb->where('lead_id', (int) $q['lead_id']); }
        if (! empty($q['status']))   { $qb->where('status', $q['status']); }
        if (! empty($q['owner_id'])) { $qb->where('owner_id', (int) $q['owner_id']); }
        if (! empty($q['due_from'])) { $qb->where('due_at >=', $q['due_from']); }
        if (! empty($q['due_to']))   { $qb->where('due_at <=', $q['due_to']); }
        if (! empty($q['overdue'])) {
            $qb->where('due_at <', date('Y-m-d H:i:s'))->where('status', 'pending');
        }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Follow-up not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['due_at'])) return $this->fail('due_at is required.', 400);
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('follow_up_create', ['subject_kind' => 'follow_up', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        if (! empty($data['status']) && $data['status'] === 'done' && empty($data['completed_at'])) {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        $this->m->update((int) $id, $data);
        $this->audit('follow_up_update', ['subject_kind' => 'follow_up', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('follow_up_delete', ['subject_kind' => 'follow_up', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

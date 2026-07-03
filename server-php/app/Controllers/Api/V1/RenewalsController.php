<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\RenewalsModel;

class RenewalsController extends BaseApiController
{
    private RenewalsModel $m;

    public function __construct()
    {
        $this->m = new RenewalsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('next_renewal_date', 'ASC');
        if (! empty($q['account_id'])) { $qb->where('account_id', (int) $q['account_id']); }
        if (! empty($q['status']))     { $qb->where('status', $q['status']); }
        if (! empty($q['before']))     { $qb->where('next_renewal_date <=', $q['before']); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Renewal not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('renewal_create', ['subject_kind' => 'renewal', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('renewal_update', ['subject_kind' => 'renewal', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('renewal_delete', ['subject_kind' => 'renewal', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

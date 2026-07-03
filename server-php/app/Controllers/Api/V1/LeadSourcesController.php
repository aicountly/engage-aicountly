<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\LeadSourcesModel;

class LeadSourcesController extends BaseApiController
{
    private LeadSourcesModel $m;

    public function __construct()
    {
        $this->m = new LeadSourcesModel();
    }

    public function index()
    {
        return $this->ok($this->m->orderBy('source_type', 'ASC')->orderBy('name', 'ASC')->findAll());
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Lead source not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['code']) || empty($data['name']) || empty($data['source_type'])) {
            return $this->fail('code, name, and source_type are required.', 400);
        }
        $id = $this->m->insert($data, true);
        $this->audit('lead_source_create', ['subject_kind' => 'lead_source', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $this->m->update((int) $id, $this->input());
        $this->audit('lead_source_update', ['subject_kind' => 'lead_source', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('lead_source_delete', ['subject_kind' => 'lead_source', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

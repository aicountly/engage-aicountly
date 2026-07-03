<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\PlansModel;

class PlansController extends BaseApiController
{
    private PlansModel $m;

    public function __construct()
    {
        $this->m = new PlansModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        $qb = $this->m->orderBy('product_id', 'ASC')->orderBy('base_price', 'ASC');
        if (! empty($q['product_id'])) {
            $qb->where('product_id', (int) $q['product_id']);
        }
        if (isset($q['active'])) {
            $qb->where('is_active', in_array($q['active'], ['1', 'true'], true));
        }
        return $this->ok($qb->findAll());
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Plan not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['code']) || empty($data['name'])) {
            return $this->fail('code and name are required.', 400);
        }
        if (isset($data['features']) && is_array($data['features'])) {
            $data['features'] = json_encode($data['features']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('plan_create', ['subject_kind' => 'plan', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['features']) && is_array($data['features'])) {
            $data['features'] = json_encode($data['features']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('plan_update', ['subject_kind' => 'plan', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('plan_delete', ['subject_kind' => 'plan', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

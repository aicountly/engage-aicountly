<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ProductsModel;

class ProductsController extends BaseApiController
{
    private ProductsModel $m;

    public function __construct()
    {
        $this->m = new ProductsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        $qb = $this->m->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC');
        if (isset($q['active'])) {
            $qb->where('is_active', in_array($q['active'], ['1', 'true'], true));
        }
        return $this->ok($qb->findAll());
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Product not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['code']) || empty($data['name'])) {
            return $this->fail('code and name are required.', 400);
        }
        $id = $this->m->insert($data, true);
        $this->audit('product_create', ['subject_kind' => 'product', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        $this->m->update((int) $id, $data);
        $this->audit('product_update', ['subject_kind' => 'product', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('product_delete', ['subject_kind' => 'product', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

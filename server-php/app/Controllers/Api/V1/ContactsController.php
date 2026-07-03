<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ContactsModel;

class ContactsController extends BaseApiController
{
    private ContactsModel $m;

    public function __construct()
    {
        $this->m = new ContactsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();

        $qb = $this->m->orderBy('name', 'ASC');
        if (! empty($q['account_id'])) { $qb->where('account_id', (int) $q['account_id']); }
        if (! empty($q['q'])) {
            $qb->groupStart()
                ->like('name',    $q['q'])
                ->orLike('email', $q['q'])
                ->orLike('mobile',$q['q'])
                ->groupEnd();
        }
        $rows = $qb->findAll($limit, $offset);
        return $this->ok([
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Contact not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['name'])) {
            return $this->fail('name is required.', 400);
        }
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('contact_create', ['subject_kind' => 'contact', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('contact_update', ['subject_kind' => 'contact', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('contact_delete', ['subject_kind' => 'contact', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

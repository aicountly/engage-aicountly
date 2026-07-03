<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\AccountsModel;
use App\Models\ContactsModel;
use App\Models\LeadsModel;

class AccountsController extends BaseApiController
{
    private AccountsModel $m;

    public function __construct()
    {
        $this->m = new AccountsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();

        $qb = $this->m->orderBy('name', 'ASC');
        if (! empty($q['q']))       { $qb->groupStart()->like('name', $q['q'])->orLike('legal_name', $q['q'])->orLike('website', $q['q'])->groupEnd(); }
        if (! empty($q['status']))  { $qb->where('status', $q['status']); }
        if (! empty($q['owner_id'])){ $qb->where('owner_id', (int) $q['owner_id']); }
        if (! empty($q['industry'])){ $qb->where('industry', $q['industry']); }

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
        if (! $row) return $this->fail('Account not found.', 404);

        $contacts = (new ContactsModel())->where('account_id', (int) $id)->orderBy('is_primary', 'DESC')->findAll();
        $leads    = (new LeadsModel())->where('account_id', (int) $id)->orderBy('id', 'DESC')->limit(50)->findAll();

        return $this->ok([
            'account'  => $row,
            'contacts' => $contacts,
            'leads'    => $leads,
        ]);
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
        $this->audit('account_create', ['subject_kind' => 'account', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('account_update', ['subject_kind' => 'account', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('account_delete', ['subject_kind' => 'account', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

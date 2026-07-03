<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\CreditLedgerModel;
use Config\Services;

class CreditLedgerController extends BaseApiController
{
    private CreditLedgerModel $m;

    public function __construct()
    {
        $this->m = new CreditLedgerModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['party_type']))      { $qb->where('party_type', $q['party_type']); }
        if (! empty($q['party_reference'])) { $qb->where('party_reference', $q['party_reference']); }
        if (! empty($q['credit_type']))     { $qb->where('credit_type', $q['credit_type']); }
        if (! empty($q['status']))          { $qb->where('status', $q['status']); }
        if (! empty($q['linked_kind']))     { $qb->where('linked_kind', $q['linked_kind']); }
        if (! empty($q['linked_id']))       { $qb->where('linked_id', $q['linked_id']); }
        if (! empty($q['from']))            { $qb->where('created_at >=', $q['from']); }
        if (! empty($q['to']))              { $qb->where('created_at <=', $q['to']); }

        $rows = $qb->findAll($limit, $offset);

        $balance = null;
        if (! empty($q['party_type']) && ! empty($q['party_reference'])) {
            $balance = $this->m->partyBalance(
                (string) $q['party_type'],
                (string) $q['party_reference'],
                ! empty($q['credit_type']) ? (string) $q['credit_type'] : null,
            );
        }

        return $this->ok([
            'items'   => $rows,
            'page'    => $page,
            'limit'   => $limit,
            'balance' => $balance,
        ]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Ledger entry not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['party_type']) || empty($data['party_reference'])) {
            return $this->fail('party_type and party_reference are required.', 400);
        }
        if (empty($data['direction']) || ! in_array($data['direction'], ['credit', 'debit'], true)) {
            return $this->fail('direction must be credit or debit.', 400);
        }
        $row = Services::creditService()->post($data);
        $this->audit('credit_entry_create', ['subject_kind' => 'credit_ledger', 'subject_id' => $row['id'] ?? null]);
        return $this->ok($row, 201);
    }

    public function delete($id = null)
    {
        Services::creditService()->reverse((int) $id, 'reversed via delete endpoint');
        $this->audit('credit_entry_reverse', ['subject_kind' => 'credit_ledger', 'subject_id' => $id]);
        return $this->ok(['reversed' => true]);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('credit_entry_update', ['subject_kind' => 'credit_ledger', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }
}

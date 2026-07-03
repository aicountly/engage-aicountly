<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ProposalLinesModel;
use App\Models\ProposalsModel;

class ProposalsController extends BaseApiController
{
    private ProposalsModel $m;
    private ProposalLinesModel $lines;

    public function __construct()
    {
        $this->m     = new ProposalsModel();
        $this->lines = new ProposalLinesModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['lead_id']))    { $qb->where('lead_id', (int) $q['lead_id']); }
        if (! empty($q['account_id'])) { $qb->where('account_id', (int) $q['account_id']); }
        if (! empty($q['status']))     { $qb->where('status', $q['status']); }
        if (! empty($q['q']))          { $qb->groupStart()->like('title', $q['q'])->orLike('proposal_code', $q['q'])->groupEnd(); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Proposal not found.', 404);
        $row['lines'] = $this->lines->where('proposal_id', (int) $id)->orderBy('sort_order', 'ASC')->findAll();
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['title'])) return $this->fail('title is required.', 400);
        if (empty($data['proposal_code'])) {
            $data['proposal_code'] = $this->m->generateCode();
        }
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('proposal_create', ['subject_kind' => 'proposal', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('proposal_update', ['subject_kind' => 'proposal', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('proposal_delete', ['subject_kind' => 'proposal', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }

    public function lines($proposalId = null)
    {
        $rows = $this->lines
            ->where('proposal_id', (int) $proposalId)
            ->orderBy('sort_order', 'ASC')
            ->findAll();
        return $this->ok($rows);
    }

    public function addLine($proposalId = null)
    {
        $data = $this->input();
        if (empty($data['description'])) return $this->fail('description is required.', 400);
        $qty  = (float) ($data['quantity']   ?? 1);
        $unit = (float) ($data['unit_price'] ?? 0);
        $disc = (float) ($data['discount_percent'] ?? 0);
        $data['proposal_id'] = (int) $proposalId;
        $data['line_total']  = round(($qty * $unit) * (1 - $disc / 100), 2);
        $id = $this->lines->insert($data, true);
        $this->recalcTotals((int) $proposalId);
        $this->audit('proposal_line_add', ['subject_kind' => 'proposal', 'subject_id' => $proposalId, 'metadata' => ['line_id' => $id]]);
        return $this->ok($this->lines->find($id), 201);
    }

    public function removeLine($proposalId = null, $lineId = null)
    {
        $this->lines->where('proposal_id', (int) $proposalId)->delete((int) $lineId);
        $this->recalcTotals((int) $proposalId);
        $this->audit('proposal_line_remove', ['subject_kind' => 'proposal', 'subject_id' => $proposalId, 'metadata' => ['line_id' => $lineId]]);
        return $this->ok(['deleted' => true]);
    }

    private function recalcTotals(int $proposalId): void
    {
        $rows = $this->lines->where('proposal_id', $proposalId)->findAll();
        $total = 0.0;
        $disc  = 0.0;
        foreach ($rows as $r) {
            $qty  = (float) ($r['quantity']         ?? 0);
            $unit = (float) ($r['unit_price']       ?? 0);
            $percent = (float) ($r['discount_percent'] ?? 0);
            $gross = $qty * $unit;
            $total += $gross;
            $disc  += $gross * ($percent / 100);
        }
        $this->m->update($proposalId, [
            'total_amount'    => round($total, 2),
            'discount_amount' => round($disc, 2),
            'net_amount'      => round($total - $disc, 2),
        ]);
    }
}

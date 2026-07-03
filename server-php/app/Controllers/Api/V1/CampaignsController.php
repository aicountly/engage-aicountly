<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\CampaignLeadsModel;
use App\Models\CampaignsModel;
use App\Models\LeadsModel;

class CampaignsController extends BaseApiController
{
    private CampaignsModel $m;

    public function __construct()
    {
        $this->m = new CampaignsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['source_portal'])) { $qb->where('source_portal', $q['source_portal']); }
        if (! empty($q['status']))        { $qb->where('status', $q['status']); }
        if (! empty($q['q']))             { $qb->groupStart()->like('name', $q['q'])->orLike('external_code', $q['q'])->groupEnd(); }

        $items = $qb->findAll($limit, $offset);

        // Attach lead counts.
        $ids = array_column($items, 'id');
        $counts = [];
        if ($ids) {
            $rows = (new CampaignLeadsModel())
                ->select('campaign_id, COUNT(*) as n')
                ->whereIn('campaign_id', $ids)
                ->groupBy('campaign_id')
                ->findAll();
            foreach ($rows as $r) {
                $counts[(int) $r['campaign_id']] = (int) $r['n'];
            }
        }
        foreach ($items as &$row) {
            $row['lead_count'] = $counts[(int) $row['id']] ?? 0;
        }

        return $this->ok(['items' => $items, 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Campaign not found.', 404);

        $leadRows = $this->db->table('engage_campaign_leads cl')
            ->select('l.id, l.lead_code, l.name, l.email, l.stage, l.lead_score, l.owner_id, cl.attribution, cl.created_at')
            ->join('engage_leads l', 'l.id = cl.lead_id')
            ->where('cl.campaign_id', (int) $id)
            ->orderBy('cl.id', 'DESC')
            ->limit(500)->get()->getResultArray();

        $row['leads'] = $leadRows;
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['name'])) return $this->fail('name is required.', 400);
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        $this->audit('campaign_create', ['subject_kind' => 'campaign', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        $this->audit('campaign_update', ['subject_kind' => 'campaign', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('campaign_delete', ['subject_kind' => 'campaign', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }
}

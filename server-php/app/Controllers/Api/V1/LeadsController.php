<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\LeadActivitiesModel;
use App\Models\LeadsModel;
use App\Models\PipelineStagesModel;
use Config\Services;

class LeadsController extends BaseApiController
{
    private LeadsModel $m;

    public function __construct()
    {
        $this->m = new LeadsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();

        $qb = $this->m->orderBy('lead_score', 'DESC')->orderBy('id', 'DESC');
        if (! empty($q['q'])) {
            $qb->groupStart()
                ->like('name',         $q['q'])
                ->orLike('organization',$q['q'])
                ->orLike('email',      $q['q'])
                ->orLike('mobile',     $q['q'])
                ->orLike('lead_code',  $q['q'])
                ->groupEnd();
        }
        if (! empty($q['stage']))               { $qb->where('stage', $q['stage']); }
        if (! empty($q['source_type']))         { $qb->where('source_type', $q['source_type']); }
        if (! empty($q['interested_product'])) { $qb->where('interested_product', $q['interested_product']); }
        if (! empty($q['priority']))            { $qb->where('priority', $q['priority']); }
        if (! empty($q['owner_id']))            { $qb->where('owner_id', (int) $q['owner_id']); }
        if (! empty($q['sales_status']))        { $qb->where('sales_status', $q['sales_status']); }
        if (! empty($q['score_min']))           { $qb->where('lead_score >=', (int) $q['score_min']); }
        if (! empty($q['score_max']))           { $qb->where('lead_score <=', (int) $q['score_max']); }
        if (! empty($q['due_from']))            { $qb->where('next_follow_up_at >=', $q['due_from']); }
        if (! empty($q['due_to']))              { $qb->where('next_follow_up_at <=', $q['due_to']); }

        $rows = $qb->findAll($limit, $offset);
        return $this->ok([
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    public function kanban()
    {
        $stages = (new PipelineStagesModel())->ordered();
        $out    = [];
        foreach ($stages as $s) {
            $leads = $this->m
                ->where('stage', $s['code'])
                ->orderBy('lead_score', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(200)
                ->findAll();
            $out[] = [
                'stage' => $s,
                'leads' => $leads,
            ];
        }
        return $this->ok($out);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Lead not found.', 404);
        return $this->ok($row);
    }

    public function activities($id = null)
    {
        if (! $this->m->find((int) $id)) return $this->fail('Lead not found.', 404);
        $rows = (new LeadActivitiesModel())
            ->where('lead_id', (int) $id)
            ->orderBy('id', 'DESC')
            ->findAll(200);
        return $this->ok($rows);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['name'])) return $this->fail('name is required.', 400);
        if (empty($data['lead_code'])) {
            $data['lead_code'] = $this->m->generateLeadCode();
        }
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $id = $this->m->insert($data, true);
        (new LeadActivitiesModel())->record($id, 'created', 'Lead created', null);
        $this->audit('lead_create', [
            'subject_kind' => 'lead', 'subject_id' => $id,
            'metadata'     => ['source_type' => $data['source_type'] ?? null],
        ]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Lead not found.', 404);
        $data = $this->input();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }
        $this->m->update((int) $id, $data);
        (new LeadActivitiesModel())->record((int) $id, 'updated', 'Lead updated', null, [
            'metadata' => ['keys' => array_keys($data)],
        ]);
        $this->audit('lead_update', ['subject_kind' => 'lead', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('lead_delete', ['subject_kind' => 'lead', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }

    public function assign($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Lead not found.', 404);
        $body = $this->input();
        $ownerId = (int) ($body['owner_id'] ?? 0) ?: null;
        $this->m->update((int) $id, ['owner_id' => $ownerId]);
        (new LeadActivitiesModel())->record((int) $id, 'assigned', 'Lead assigned', null, [
            'metadata' => ['owner_id' => $ownerId],
        ]);
        $this->audit('lead_assign', [
            'subject_kind' => 'lead', 'subject_id' => $id,
            'metadata'     => ['owner_id' => $ownerId],
        ]);
        return $this->ok($this->m->find((int) $id));
    }

    public function moveStage($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Lead not found.', 404);
        $body = $this->input();
        $stage = (string) ($body['stage'] ?? '');
        if ($stage === '') return $this->fail('stage is required.', 400);

        $stages = (new PipelineStagesModel())->findAll();
        $codes = array_column($stages, 'code');
        if (! in_array($stage, $codes, true)) {
            return $this->fail('Unknown stage.', 400, ['allowed' => $codes]);
        }

        $updates = ['stage' => $stage];
        // Bump probability to the stage default if not explicitly set.
        foreach ($stages as $s) {
            if ($s['code'] === $stage) {
                $updates['conversion_probability'] = (int) $s['default_probability'];
                if (! empty($s['is_won'])) {
                    $updates['sales_status'] = 'won';
                }
                if (! empty($s['is_lost'])) {
                    $updates['sales_status'] = 'lost';
                }
                break;
            }
        }
        $this->m->update((int) $id, $updates);

        (new LeadActivitiesModel())->record((int) $id, 'stage_moved', 'Stage moved to ' . $stage, null, [
            'metadata' => ['from' => $row['stage'], 'to' => $stage],
        ]);
        $eventName = $stage === 'converted' ? 'lead.converted' : 'lead_stage_move';
        $this->audit($eventName, [
            'subject_kind' => 'lead', 'subject_id' => $id,
            'metadata'     => ['from' => $row['stage'], 'to' => $stage],
        ]);
        return $this->ok($this->m->find((int) $id));
    }

    public function score($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Lead not found.', 404);

        $result = Services::salesBot()->dispatch('score_lead', ['lead_id' => (int) $id]);
        return $this->ok([
            'lead'   => $this->m->find((int) $id),
            'report' => $result['report'] ?? null,
        ]);
    }

    public function note($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Lead not found.', 404);
        $body = $this->input();
        $text = trim((string) ($body['note'] ?? ''));
        if ($text === '') return $this->fail('note is required.', 400);

        (new LeadActivitiesModel())->record((int) $id, 'note', 'Note', $text);
        $this->m->update((int) $id, ['last_contacted_at' => date('Y-m-d H:i:s')]);
        $this->audit('lead_note', ['subject_kind' => 'lead', 'subject_id' => $id]);
        return $this->ok(['ok' => true]);
    }
}

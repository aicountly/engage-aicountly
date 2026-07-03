<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\LeadsModel;
use App\Models\PipelineStagesModel;

class PipelineController extends BaseApiController
{
    private PipelineStagesModel $m;

    public function __construct()
    {
        $this->m = new PipelineStagesModel();
    }

    public function index()
    {
        return $this->ok($this->m->ordered());
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Stage not found.', 404);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['code']) || empty($data['name'])) {
            return $this->fail('code and name required.', 400);
        }
        $id = $this->m->insert($data, true);
        $this->audit('pipeline_stage_create', ['subject_kind' => 'pipeline_stage', 'subject_id' => $id]);
        return $this->ok($this->m->find($id), 201);
    }

    public function update($id = null)
    {
        $this->m->update((int) $id, $this->input());
        $this->audit('pipeline_stage_update', ['subject_kind' => 'pipeline_stage', 'subject_id' => $id]);
        return $this->ok($this->m->find((int) $id));
    }

    public function delete($id = null)
    {
        $this->m->delete((int) $id);
        $this->audit('pipeline_stage_delete', ['subject_kind' => 'pipeline_stage', 'subject_id' => $id]);
        return $this->ok(['deleted' => true]);
    }

    public function summary()
    {
        $stages = $this->m->ordered();
        $counts = [];
        try {
            $rows = (new LeadsModel())
                ->select('stage, COUNT(*) as n')
                ->groupBy('stage')
                ->findAll();
            foreach ($rows as $r) {
                $counts[(string) $r['stage']] = (int) $r['n'];
            }
        } catch (\Throwable $e) {
            // Leads table may not exist yet during first-boot; treat as empty.
        }

        return $this->ok([
            'stages' => array_map(static function ($s) use ($counts) {
                return [
                    'code'                => $s['code'],
                    'name'                => $s['name'],
                    'sort_order'          => (int) $s['sort_order'],
                    'is_terminal'         => (bool) $s['is_terminal'],
                    'is_won'              => (bool) $s['is_won'],
                    'is_lost'             => (bool) $s['is_lost'],
                    'default_probability' => (int) $s['default_probability'],
                    'colour'              => $s['colour'],
                    'lead_count'          => $counts[$s['code']] ?? 0,
                ];
            }, $stages),
        ]);
    }
}

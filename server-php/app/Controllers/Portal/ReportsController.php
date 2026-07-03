<?php

namespace App\Controllers\Portal;

use App\Controllers\BaseApiController;
use App\Models\BotReportsModel;

/**
 * GET /api/v1/portal/bot/reports        — Console polls a summary/list.
 * GET /api/v1/portal/bot/reports/(:num) — Console fetches full report row.
 */
class ReportsController extends BaseApiController
{
    public function index()
    {
        $q  = $this->request->getGet();
        $m  = new BotReportsModel();
        $qb = $m->orderBy('id', 'DESC');
        if (! empty($q['action']))      { $qb->where('action', $q['action']); }
        if (! empty($q['entity_kind'])) { $qb->where('entity_kind', $q['entity_kind']); }
        if (! empty($q['from']))        { $qb->where('created_at >=', $q['from']); }
        if (! empty($q['to']))          { $qb->where('created_at <=', $q['to']); }
        $limit = min(500, max(1, (int) ($q['limit'] ?? 100)));

        $rows = $qb->findAll($limit);

        $summary = $m
            ->select('action, approval_status, COUNT(*) as n')
            ->where('created_at >=', $q['from'] ?? date('Y-m-d H:i:s', strtotime('-24 hours')))
            ->groupBy(['action', 'approval_status'])
            ->findAll();

        return $this->ok([
            'portal'  => 'engage',
            'reports' => $rows,
            'summary' => $summary,
        ]);
    }

    public function show($id = null)
    {
        $row = (new BotReportsModel())->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Not found.', 404);
    }
}

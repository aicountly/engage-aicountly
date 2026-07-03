<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\BotReportsModel;

class BotReportsController extends BaseApiController
{
    private BotReportsModel $m;

    public function __construct()
    {
        $this->m = new BotReportsModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['action']))          { $qb->where('action', $q['action']); }
        if (! empty($q['entity_kind']))     { $qb->where('entity_kind', $q['entity_kind']); }
        if (! empty($q['entity_id']))       { $qb->where('entity_id',  (string) $q['entity_id']); }
        if (! empty($q['mode']))            { $qb->where('mode', $q['mode']); }
        if (! empty($q['approval_status'])) { $qb->where('approval_status', $q['approval_status']); }
        if (! empty($q['from']))            { $qb->where('created_at >=', $q['from']); }
        if (! empty($q['to']))              { $qb->where('created_at <=', $q['to']); }

        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $row = $this->m->find((int) $id);
        return $row ? $this->ok($row) : $this->fail('Report not found.', 404);
    }

    /**
     * Rolled-up local report used by the "Local bot reports" page.
     */
    public function localReports()
    {
        $sinceDays = max(1, (int) ($this->request->getGet('days') ?? 30));
        $from = date('Y-m-d H:i:s', strtotime("-{$sinceDays} days"));

        $rows = $this->m
            ->select('action, mode, approval_status, COUNT(*) as n')
            ->where('created_at >=', $from)
            ->groupBy(['action', 'mode', 'approval_status'])
            ->findAll();

        $recent = $this->m->orderBy('id', 'DESC')->limit(200)->findAll();

        return $this->ok([
            'since_days'      => $sinceDays,
            'aggregations'    => $rows,
            'recent_reports'  => $recent,
        ]);
    }
}

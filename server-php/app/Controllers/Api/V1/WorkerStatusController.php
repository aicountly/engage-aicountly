<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\WorkerHealthModel;
use Config\Services;

class WorkerStatusController extends BaseApiController
{
    public function index()
    {
        $m = new WorkerHealthModel();
        $recent = $m->orderBy('id', 'DESC')->findAll(100);
        $lastOk = $m->where('status', 'success')->orderBy('id', 'DESC')->first();
        $lastErr= $m->whereIn('status', ['error', 'skipped'])->orderBy('id', 'DESC')->first();

        $agg = $m
            ->select('job_kind, status, COUNT(*) as n')
            ->groupBy(['job_kind', 'status'])
            ->findAll();

        $configured = Services::workerClient()->isConfigured();
        $live = null;
        if ($configured && ($this->request->getGet('probe') ?? '0') === '1') {
            $live = Services::workerClient()->ping();
        }

        return $this->ok([
            'configured'   => $configured,
            'worker_base'  => (string) env('WORKER_BASE_URL', ''),
            'recent'       => $recent,
            'aggregations' => $agg,
            'last_success' => $lastOk,
            'last_failure' => $lastErr,
            'live_probe'   => $live,
        ]);
    }
}

<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ConsoleSyncStatusModel;

class ConsoleSyncStatusController extends BaseApiController
{
    public function index()
    {
        $m = new ConsoleSyncStatusModel();
        $recent = $m->orderBy('id', 'DESC')->findAll(100);

        $agg = $m
            ->select('event_kind, direction, success, COUNT(*) as n')
            ->groupBy(['event_kind', 'direction', 'success'])
            ->findAll();

        $lastSuccess = $m->where('success', true)->orderBy('id', 'DESC')->first();
        $lastFailure = $m->where('success', false)->orderBy('id', 'DESC')->first();

        return $this->ok([
            'recent'        => $recent,
            'aggregations'  => $agg,
            'last_success'  => $lastSuccess,
            'last_failure'  => $lastFailure,
            'configured'    => (string) env('CONSOLE_API_BASE_URL', '') !== ''
                            && (string) env('CONSOLE_INBOUND_KEY', '')  !== ''
                            && (string) env('ENGAGE_SERVICE_KEY', '')   !== '',
            'console_base'  => (string) env('CONSOLE_API_BASE_URL', ''),
        ]);
    }
}

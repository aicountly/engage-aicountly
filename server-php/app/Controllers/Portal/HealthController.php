<?php

namespace App\Controllers\Portal;

use App\Controllers\BaseApiController;
use App\Models\BotReportsModel;
use App\Models\SettingsModel;

/**
 * GET /api/v1/portal/bot/health — Console polls this to check Engage status.
 */
class HealthController extends BaseApiController
{
    public function show()
    {
        $bot = new BotReportsModel();
        $reportsLast24h = $bot->where('created_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))->countAllResults();
        $reportsLast7d  = $bot->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))->countAllResults();
        $lastReport     = $bot->orderBy('id', 'DESC')->first();

        return $this->ok([
            'service'          => 'aicountly-engage-api',
            'portal'           => 'engage',
            'status'           => 'ready',
            'mode'             => (new SettingsModel())->getSetting('bot_mode', 'confirm'),
            'reports_last_24h' => (int) $reportsLast24h,
            'reports_last_7d'  => (int) $reportsLast7d,
            'last_report_at'   => $lastReport['created_at'] ?? null,
            'timestamp'        => gmdate('c'),
        ]);
    }
}

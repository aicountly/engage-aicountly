<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'key'         => 'bot_mode',
                'value_json'  => json_encode('confirm'),
                'description' => 'Sales Bot mode. One of: confirm | auto. Confirm requires approval; Auto allows low-risk actions immediately.',
            ],
            [
                'key'         => 'allowed_auto_actions',
                'value_json'  => json_encode([
                    'score_lead',
                    'identify_hot_leads',
                    'identify_stale_leads',
                    'draft_summary',
                    'suggest_stage',
                    'report_generation',
                ]),
                'description' => 'Bot actions permitted to run without approval when bot_mode = auto.',
            ],
            [
                'key'         => 'theme_brand_colour',
                'value_json'  => json_encode('#16a34a'),
                'description' => 'AICOUNTLY green-white primary colour reference.',
            ],
            [
                'key'         => 'lead_score_thresholds',
                'value_json'  => json_encode(['hot' => 75, 'warm' => 50, 'cold' => 25]),
                'description' => 'Score thresholds used for hot/warm/cold buckets and badges.',
            ],
            [
                'key'         => 'stale_lead_days',
                'value_json'  => json_encode(14),
                'description' => 'Number of days since last_contacted_at before a lead is marked stale by the bot.',
            ],
            [
                'key'         => 'renewal_lead_days',
                'value_json'  => json_encode([90, 60, 30, 7]),
                'description' => 'Days-before-renewal-expiry when the renewal reminder bot creates follow-ups.',
            ],
            [
                'key'         => 'llm_enabled',
                'value_json'  => json_encode(false),
                'description' => 'Enable LLM provider for Sales Bot drafts. Deterministic fallback when disabled.',
            ],
            [
                'key'         => 'llm_provider',
                'value_json'  => json_encode(''),
                'description' => 'openai | anthropic | gemini. Key + model live in .env (ENGAGE_LLM_*).',
            ],
            [
                'key'         => 'console_integration_enabled',
                'value_json'  => json_encode(true),
                'description' => 'Fan out audit events / approval requests to console.aicountly.org.',
            ],
            [
                'key'         => 'worker_integration_enabled',
                'value_json'  => json_encode(false),
                'description' => 'Use worker.apis.aicountly.com for Playwright screenshots / UI review jobs.',
            ],
            [
                'key'         => 'credit_large_threshold',
                'value_json'  => json_encode((int) env('ENGAGE_CREDIT_LARGE_THRESHOLD', 10000)),
                'description' => 'Credit adjustments above this amount go through Approvals.',
            ],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($rows as &$r) {
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }

        $this->db->table('engage_settings')->ignore(true)->insertBatch($rows);
    }
}

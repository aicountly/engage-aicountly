<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class BotActionsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'qualify_lead',             'name' => 'Lead Qualification',        'category' => 'analysis',      'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'score_lead',               'name' => 'Lead Scoring',              'category' => 'analysis',      'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'recommend_follow_up',      'name' => 'Follow-up Recommendation',  'category' => 'analysis',      'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'draft_email',              'name' => 'Draft Email',               'category' => 'communication', 'risk_level' => 'medium', 'default_approval' => 'required',     'is_auto_eligible' => false],
            ['code' => 'draft_whatsapp',           'name' => 'Draft WhatsApp Message',    'category' => 'communication', 'risk_level' => 'medium', 'default_approval' => 'required',     'is_auto_eligible' => false],
            ['code' => 'draft_proposal_summary',   'name' => 'Draft Proposal Summary',    'category' => 'proposal',      'risk_level' => 'medium', 'default_approval' => 'required',     'is_auto_eligible' => false],
            ['code' => 'suggest_pricing',          'name' => 'Suggest Pricing/Discount',  'category' => 'pricing',       'risk_level' => 'high',   'default_approval' => 'required',     'is_auto_eligible' => false],
            ['code' => 'update_stage',             'name' => 'Update CRM Stage',          'category' => 'crm',           'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'schedule_follow_up',       'name' => 'Schedule Follow-up',        'category' => 'crm',           'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'identify_hot_leads',       'name' => 'Identify Hot Leads',        'category' => 'analysis',      'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'identify_stale_leads',     'name' => 'Identify Stale Leads',      'category' => 'analysis',      'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'prepare_renewal',          'name' => 'Prepare Renewal Follow-up', 'category' => 'renewal',       'risk_level' => 'medium', 'default_approval' => 'required',     'is_auto_eligible' => false],
            ['code' => 'convert_reach_lead',       'name' => 'Convert Reach Campaign Lead','category'=> 'reach',         'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
            ['code' => 'request_approval',         'name' => 'Request Admin Approval',    'category' => 'approval',      'risk_level' => 'low',    'default_approval' => 'not_required', 'is_auto_eligible' => true],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $exists = $this->db->table('engage_bot_actions')->where('code', $row['code'])->countAllResults() > 0;
            if ($exists) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('engage_bot_actions')->insert($row);
        }
    }
}

<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PipelineStagesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'new',                 'name' => 'New',                 'sort_order' => 10,  'default_probability' => 5,   'colour' => '#94a3b8'],
            ['code' => 'qualified',           'name' => 'Qualified',           'sort_order' => 20,  'default_probability' => 15,  'colour' => '#38bdf8'],
            ['code' => 'contacted',           'name' => 'Contacted',           'sort_order' => 30,  'default_probability' => 25,  'colour' => '#0ea5e9'],
            ['code' => 'demo_required',       'name' => 'Demo Required',       'sort_order' => 40,  'default_probability' => 35,  'colour' => '#a855f7'],
            ['code' => 'proposal_required',   'name' => 'Proposal Required',   'sort_order' => 50,  'default_probability' => 45,  'colour' => '#8b5cf6'],
            ['code' => 'proposal_sent',       'name' => 'Proposal Sent',       'sort_order' => 60,  'default_probability' => 55,  'colour' => '#6366f1'],
            ['code' => 'negotiation',         'name' => 'Negotiation',         'sort_order' => 70,  'default_probability' => 70,  'colour' => '#f59e0b'],
            ['code' => 'waiting_for_approval','name' => 'Waiting for Approval','sort_order' => 80,  'default_probability' => 80,  'colour' => '#eab308'],
            ['code' => 'converted',           'name' => 'Converted',           'sort_order' => 90,  'default_probability' => 100, 'colour' => '#16a34a', 'is_terminal' => true, 'is_won'  => true],
            ['code' => 'lost',                'name' => 'Lost',                'sort_order' => 100, 'default_probability' => 0,   'colour' => '#dc2626', 'is_terminal' => true, 'is_lost' => true],
            ['code' => 'nurture',             'name' => 'Nurture',             'sort_order' => 110, 'default_probability' => 15,  'colour' => '#78716c'],
            ['code' => 'not_relevant',        'name' => 'Not Relevant',        'sort_order' => 120, 'default_probability' => 0,   'colour' => '#64748b', 'is_terminal' => true],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $exists = $this->db->table('engage_pipeline_stages')->where('code', $row['code'])->countAllResults() > 0;
            if ($exists) {
                continue;
            }
            $row['is_terminal'] = $row['is_terminal'] ?? false;
            $row['is_won']      = $row['is_won'] ?? false;
            $row['is_lost']     = $row['is_lost'] ?? false;
            $row['created_at']  = $now;
            $row['updated_at']  = $now;
            $this->db->table('engage_pipeline_stages')->insert($row);
        }
    }
}

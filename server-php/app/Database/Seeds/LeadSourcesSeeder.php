<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LeadSourcesSeeder extends Seeder
{
    public function run(): void
    {
        // Source types: reach campaign, direct, referral, website, manual, import, webinar, social, other
        $rows = [
            ['code' => 'reach_campaign', 'name' => 'Reach Campaign',   'source_type' => 'reach_campaign', 'source_portal' => 'reach.aicountly.org', 'default_weight' => 20],
            ['code' => 'direct',         'name' => 'Direct',           'source_type' => 'direct',         'source_portal' => null,                  'default_weight' => 25],
            ['code' => 'referral',       'name' => 'Referral',         'source_type' => 'referral',       'source_portal' => null,                  'default_weight' => 30],
            ['code' => 'website',        'name' => 'Website',          'source_type' => 'website',        'source_portal' => 'aicountly.com',       'default_weight' => 15],
            ['code' => 'manual',         'name' => 'Manual Entry',     'source_type' => 'manual',         'source_portal' => null,                  'default_weight' => 20],
            ['code' => 'import',         'name' => 'Import',           'source_type' => 'import',         'source_portal' => null,                  'default_weight' => 10],
            ['code' => 'webinar',        'name' => 'Webinar',          'source_type' => 'webinar',        'source_portal' => null,                  'default_weight' => 25],
            ['code' => 'social',         'name' => 'Social Media',     'source_type' => 'social',        'source_portal' => null,                  'default_weight' => 15],
            ['code' => 'other',          'name' => 'Other',            'source_type' => 'other',          'source_portal' => null,                  'default_weight' => 10],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $exists = $this->db->table('engage_lead_sources')->where('code', $row['code'])->countAllResults() > 0;
            if ($exists) {
                continue;
            }
            $row['is_active']  = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('engage_lead_sources')->insert($row);
        }
    }
}

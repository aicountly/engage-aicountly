<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'books',        'name' => 'Aicountly Books',       'category' => 'accounting'],
            ['code' => 'auditor',      'name' => 'Aicountly Auditor',     'category' => 'audit'],
            ['code' => 'fr',           'name' => 'Aicountly FR',          'category' => 'financial_reporting'],
            ['code' => 'secretarial',  'name' => 'Aicountly Secretarial', 'category' => 'compliance'],
            ['code' => 'calendar',     'name' => 'Aicountly Calendar',    'category' => 'productivity'],
            ['code' => 'contacts',     'name' => 'Aicountly Contacts',    'category' => 'productivity'],
            ['code' => 'vault',        'name' => 'Aicountly Vault',       'category' => 'storage'],
            ['code' => 'chat',         'name' => 'Aicountly Chat',        'category' => 'communication'],
            ['code' => 'docs',         'name' => 'Aicountly Docs',        'category' => 'productivity'],
            ['code' => 'hrms',         'name' => 'Aicountly HRMS',        'category' => 'hr'],
            ['code' => 'ourpeople',    'name' => 'Aicountly OurPeople',   'category' => 'hr'],
            ['code' => 'flow',         'name' => 'Aicountly Flow',        'category' => 'internal'],
            ['code' => 'console',      'name' => 'Aicountly Console',     'category' => 'internal'],
            ['code' => 'other',        'name' => 'Other / Custom',        'category' => 'other'],
            // Aicountly Academy courses — same rows as migration 2026-10-23-000110_AddAcademyProducts.
            ['code' => 'academy',      'name' => 'Aicountly Academy',     'category' => 'Academy'],
            ['code' => 'edge',         'name' => 'Aicountly Edge',        'category' => 'Academy'],
            ['code' => 'aspire',       'name' => 'Aicountly Aspire',      'category' => 'Academy'],
        ];

        $now = date('Y-m-d H:i:s');
        $sort = 10;
        foreach ($rows as $row) {
            $exists = $this->db->table('engage_products')->where('code', $row['code'])->countAllResults() > 0;
            if ($exists) {
                continue;
            }
            $row['sort_order'] = $sort;
            $row['is_active']  = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('engage_products')->insert($row);
            $sort += 10;
        }
    }
}

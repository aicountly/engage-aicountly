<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'books',        'name' => 'AICOUNTLY Books',       'category' => 'accounting'],
            ['code' => 'auditor',      'name' => 'AICOUNTLY Auditor',     'category' => 'audit'],
            ['code' => 'fr',           'name' => 'AICOUNTLY FR',          'category' => 'financial_reporting'],
            ['code' => 'secretarial',  'name' => 'AICOUNTLY Secretarial', 'category' => 'compliance'],
            ['code' => 'calendar',     'name' => 'AICOUNTLY Calendar',    'category' => 'productivity'],
            ['code' => 'contacts',     'name' => 'AICOUNTLY Contacts',    'category' => 'productivity'],
            ['code' => 'vault',        'name' => 'AICOUNTLY Vault',       'category' => 'storage'],
            ['code' => 'chat',         'name' => 'AICOUNTLY Chat',        'category' => 'communication'],
            ['code' => 'docs',         'name' => 'AICOUNTLY Docs',        'category' => 'productivity'],
            ['code' => 'hrms',         'name' => 'AICOUNTLY HRMS',        'category' => 'hr'],
            ['code' => 'ourpeople',    'name' => 'AICOUNTLY OurPeople',   'category' => 'hr'],
            ['code' => 'flow',         'name' => 'AICOUNTLY Flow',        'category' => 'internal'],
            ['code' => 'console',      'name' => 'AICOUNTLY Console',     'category' => 'internal'],
            ['code' => 'other',        'name' => 'Other / Custom',        'category' => 'other'],
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

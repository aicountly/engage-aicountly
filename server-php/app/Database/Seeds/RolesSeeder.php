<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code'        => 'super_admin',
                'name'        => 'Super Admin',
                'description' => 'Full control of the Engage portal. The only role recognised by Engage (superadmin-only portal).',
            ],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $exists = $this->db->table('engage_roles')->where('code', $row['code'])->countAllResults() > 0;
            if ($exists) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('engage_roles')->insert($row);
        }
    }
}

<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Engage role catalog (Console app_code `engage`). Which staff member holds
 * which role is decided in Console → Roles & Access and mirrored at sign-in;
 * migration 2026-10-14-000080 upserts the same rows on existing deployments.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code'        => 'super_admin',
                'name'        => 'Engage Administrator',
                'description' => 'Full control of Engage, including system settings and bot settings. Assigned in Console → Roles & Access.',
            ],
            [
                'code'        => 'member',
                'name'        => 'Engage Member',
                'description' => 'Everything in Engage except the administrator-only routes (system settings, bot settings). Assigned in Console → Roles & Access.',
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

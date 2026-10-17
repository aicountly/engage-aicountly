<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Seller programme roles (Console app_code `engage`, contract "Console roles"):
 *
 *   super_admin (100) · sales_manager (70) · seller_manager (60) · kyc_reviewer (40) · member (10)
 *
 * Who holds which role is still decided in Console → Roles & Access and mirrored at sign-in
 * (ConsoleRoleSync::CATALOG). Seeders only run on a first deploy, so the rows arrive here.
 * engage_roles gains `level` so the SPA / docs can show the ordering.
 */
class AddSellerProgramRoles extends Migration
{
    public const ROLES = [
        ['super_admin', 'Engage Administrator', 100, 'Full control of Engage, including system settings, bot settings, Partner Master, sellers and commission plans. Assigned in Console → Roles & Access.'],
        ['sales_manager', 'Sales Manager', 70, 'Publishes / retires commission plans, confirms customer payments and reverses commission entries. Assigned in Console → Roles & Access.'],
        ['seller_manager', 'Seller Manager', 60, 'Approves seller applications, suspends / reactivates / terminates sellers, manages seller agreements and verifies KYC. Assigned in Console → Roles & Access.'],
        ['kyc_reviewer', 'KYC Reviewer', 40, 'Reviews seller KYC documents and verifies or rejects KYC. Assigned in Console → Roles & Access.'],
        ['member', 'Engage Member', 10, 'Everything in Engage except the role-gated routes (settings, bot settings, Partner Master, seller administration, commission plans). Assigned in Console → Roles & Access.'],
    ];

    public function up(): void
    {
        $this->db->query('ALTER TABLE engage_roles ADD COLUMN IF NOT EXISTS level INTEGER NOT NULL DEFAULT 0');
        $sql = 'INSERT INTO engage_roles (code, name, description, level, created_at, updated_at)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (code) DO UPDATE
                   SET name = EXCLUDED.name, description = EXCLUDED.description, level = EXCLUDED.level,
                       updated_at = CURRENT_TIMESTAMP';
        foreach (self::ROLES as [$code, $name, $level, $desc]) {
            $this->db->query($sql, [$code, $name, $desc, $level]);
        }
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM engage_user_roles WHERE role_id IN (SELECT id FROM engage_roles WHERE code IN ('sales_manager','seller_manager','kyc_reviewer'))");
        $this->db->query("DELETE FROM engage_roles WHERE code IN ('sales_manager','seller_manager','kyc_reviewer')");
        $this->db->query('ALTER TABLE engage_roles DROP COLUMN IF EXISTS level');
    }
}

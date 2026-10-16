<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Staff roles come from Console (console.aicountly.org → Roles & Access).
 *
 * - engage_users gains the Console link and sync bookkeeping:
 *     console_user_id   — Console user id (used for the user-access pull)
 *     roles_synced_at   — last time Console confirmed the roles (12h grace
 *                         window when Console is unreachable at refresh)
 *     authz_version     — Console's authz_version at that sync
 *     tokens_revoked_at — JWTs issued before this instant are rejected
 *                         (set when Console revokes access)
 * - the Engage role catalog (Console app_code `engage`) is upserted:
 *     super_admin (Engage Administrator) and member (Engage Member).
 *   Seeders only run on the first deploy, so the new `member` role has to
 *   arrive through a migration.
 *
 * Raw DDL with IF NOT EXISTS so a re-run (or a partially applied schema)
 * is harmless.
 */
class AddConsoleRoleSync extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE engage_users ADD COLUMN IF NOT EXISTS console_user_id BIGINT NULL');
        $this->db->query('ALTER TABLE engage_users ADD COLUMN IF NOT EXISTS roles_synced_at TIMESTAMP NULL');
        $this->db->query('ALTER TABLE engage_users ADD COLUMN IF NOT EXISTS authz_version INTEGER NULL');
        $this->db->query('ALTER TABLE engage_users ADD COLUMN IF NOT EXISTS tokens_revoked_at TIMESTAMP NULL');
        $this->db->query('CREATE INDEX IF NOT EXISTS engage_users_console_user_id_idx ON engage_users (console_user_id)');

        $sql = 'INSERT INTO engage_roles (code, name, description, created_at, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (code) DO UPDATE
                   SET name = EXCLUDED.name, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP';
        $this->db->query($sql, [
            'super_admin',
            'Engage Administrator',
            'Full control of Engage, including system settings and bot settings. Assigned in Console → Roles & Access.',
        ]);
        $this->db->query($sql, [
            'member',
            'Engage Member',
            'Everything in Engage except the administrator-only routes (system settings, bot settings). Assigned in Console → Roles & Access.',
        ]);
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM engage_roles WHERE code = 'member'");
        $this->db->query('DROP INDEX IF EXISTS engage_users_console_user_id_idx');
        foreach (['tokens_revoked_at', 'authz_version', 'roles_synced_at', 'console_user_id'] as $col) {
            $this->db->query("ALTER TABLE engage_users DROP COLUMN IF EXISTS {$col}");
        }
    }
}

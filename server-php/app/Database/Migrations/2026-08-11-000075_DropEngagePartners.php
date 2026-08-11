<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Reverses 2026-08-11-000070_CreateEngagePartners.
 *
 * Partner data now lives only on the Partner Portal (partner.aicountly.com) —
 * Engage's Partner Master screens call that portal's admin API instead of a
 * local table, so Engage stores no partner rows. This is a forward migration
 * rather than an edit to the original create migration, per normal practice:
 * that file may already have run in some environment and migrations are not
 * rewritten after the fact.
 */
class DropEngagePartners extends Migration
{
    public function up(): void
    {
        $this->db->query('DROP INDEX IF EXISTS engage_partners_email_live_uniq');
        $this->db->query('DROP INDEX IF EXISTS engage_partners_email_lookup');
        $this->forge->dropTable('engage_partners', true);
    }

    /**
     * Recreates the empty table structure for migration-tooling consistency.
     * This does NOT restore any data that existed before `up()` ran — a drop
     * is inherently lossy. Confirmed with the team before this migration was
     * written: no environment held real partner records in this table.
     */
    public function down(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'partner_uid'     => ['type' => 'VARCHAR', 'constraint' => 36, 'null' => false],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'contact_name'    => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 48,  'null' => true],
            'partner_type'    => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'website'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'country'         => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 96,  'null' => true],
            'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'password_set_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'active'],
            'account_id'      => ['type' => 'BIGINT', 'null' => true],
            'owner_id'        => ['type' => 'BIGINT', 'null' => true],
            'last_login_at'   => ['type' => 'TIMESTAMP', 'null' => true],
            'last_login_ip'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'failed_attempts' => ['type' => 'INTEGER', 'default' => 0],
            'locked_until'    => ['type' => 'TIMESTAMP', 'null' => true],
            'notes'           => ['type' => 'TEXT', 'null' => true],
            'metadata'        => ['type' => 'JSONB', 'null' => true],
            'created_at'      => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'      => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'deleted_at'      => ['type' => 'TIMESTAMP', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('partner_uid');
        $this->forge->addKey('name');
        $this->forge->addKey('status');
        $this->forge->addKey('account_id');
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('engage_partners', true);

        $this->db->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS engage_partners_email_live_uniq
             ON engage_partners (LOWER(email)) WHERE deleted_at IS NULL'
        );
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS engage_partners_email_lookup
             ON engage_partners (LOWER(email))'
        );
    }
}

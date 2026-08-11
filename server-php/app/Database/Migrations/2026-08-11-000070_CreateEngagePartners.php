<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Partner Master — the single source of truth for partners.
 *
 * Owned and maintained by Engage (engage.aicountly.org). The Partner Portal
 * (partner.aicountly.com) reads this table for authentication only and never
 * creates partners; there is deliberately no second partner schema.
 */
class CreateEngagePartners extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            // Stable public identifier handed to the Partner Portal and integrations.
            'partner_uid'     => ['type' => 'VARCHAR', 'constraint' => 36, 'null' => false],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'contact_name'    => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 48,  'null' => true],
            'partner_type'    => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'website'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'country'         => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 96,  'null' => true],
            // Credentials are set from Engage only. NULL = portal access not enabled yet.
            'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'password_set_at' => ['type' => 'TIMESTAMP', 'null' => true],
            // active | inactive — only `active` may authenticate on the Partner Portal.
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
            // Soft delete — deleted partners keep their audit trail but can never log in.
            'deleted_at'      => ['type' => 'TIMESTAMP', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('partner_uid');
        $this->forge->addKey('name');
        $this->forge->addKey('status');
        $this->forge->addKey('account_id');
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('engage_partners', true);

        // Email is unique among live partners only, so a deleted partner's email
        // can be reused. Partial indexes are not expressible through Forge.
        $this->db->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS engage_partners_email_live_uniq
             ON engage_partners (LOWER(email)) WHERE deleted_at IS NULL'
        );

        // Login lookups always filter on the live set.
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS engage_partners_email_lookup
             ON engage_partners (LOWER(email))'
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS engage_partners_email_live_uniq');
        $this->db->query('DROP INDEX IF EXISTS engage_partners_email_lookup');
        $this->forge->dropTable('engage_partners', true);
    }
}

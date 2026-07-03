<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageAuditLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGSERIAL'],
            'event'        => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'actor_id'     => ['type' => 'BIGINT', 'null' => true],
            'actor_email'  => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'actor_role'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'subject_kind' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'subject_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'metadata'     => ['type' => 'JSONB', 'null' => true],
            'created_at'   => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('event');
        $this->forge->addKey('actor_id');
        $this->forge->addKey('subject_kind');
        $this->forge->addKey('created_at');
        $this->forge->createTable('engage_audit_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_audit_logs', true);
    }
}

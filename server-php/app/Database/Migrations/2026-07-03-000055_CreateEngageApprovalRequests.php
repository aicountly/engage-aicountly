<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageApprovalRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'correlation_id'        => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'kind'                  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'subject_kind'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'subject_id'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'risk_level'            => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'medium'],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'payload'               => ['type' => 'JSONB', 'null' => true],
            'requested_by'          => ['type' => 'BIGINT', 'null' => true],
            'requester_kind'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'user'],
            'decided_by'            => ['type' => 'BIGINT', 'null' => true],
            'decided_at'            => ['type' => 'TIMESTAMP', 'null' => true],
            'decision_notes'        => ['type' => 'TEXT', 'null' => true],
            'console_approval_id'   => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'console_status'        => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'production_confirmation'=> ['type' => 'BOOLEAN', 'default' => false],
            'executed_at'           => ['type' => 'TIMESTAMP', 'null' => true],
            'execution_result'      => ['type' => 'JSONB', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('kind');
        $this->forge->addKey('status');
        $this->forge->addKey('subject_kind');
        $this->forge->addKey('created_at');
        $this->forge->createTable('engage_approval_requests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_approval_requests', true);
    }
}

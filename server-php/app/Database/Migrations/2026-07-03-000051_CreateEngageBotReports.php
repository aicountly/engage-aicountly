<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageBotReports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                       => ['type' => 'BIGSERIAL'],
            'queue_id'                 => ['type' => 'BIGINT', 'null' => true],
            'action'                   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_kind'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'entity_id'                => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'mode'                     => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'confirm'],
            'understanding'            => ['type' => 'TEXT', 'null' => false, 'default' => ''],
            'data_accessed'            => ['type' => 'JSONB', 'null' => true],
            'recommendation'           => ['type' => 'TEXT', 'null' => true],
            'action_proposed'          => ['type' => 'TEXT', 'null' => true],
            'action_taken'             => ['type' => 'TEXT', 'null' => true],
            'approval_status'          => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'not_required'],
            'approval_id'              => ['type' => 'BIGINT', 'null' => true],
            'message_draft'            => ['type' => 'TEXT', 'null' => true],
            'proposal_draft'           => ['type' => 'JSONB', 'null' => true],
            'evidence'                 => ['type' => 'JSONB', 'null' => true],
            'next_recommended_action'  => ['type' => 'TEXT', 'null' => true],
            'errors'                   => ['type' => 'JSONB', 'null' => true],
            'created_at'               => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('action');
        $this->forge->addKey('entity_kind');
        $this->forge->addKey('created_at');
        $this->forge->createTable('engage_bot_reports', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_bot_reports', true);
    }
}

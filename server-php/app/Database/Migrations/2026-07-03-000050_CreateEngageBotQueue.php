<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageBotQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'action'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_kind'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'entity_id'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'payload'           => ['type' => 'JSONB', 'null' => true],
            'mode'              => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'confirm'],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'attempts'          => ['type' => 'INTEGER', 'default' => 0],
            'last_error'        => ['type' => 'TEXT', 'null' => true],
            'run_at'            => ['type' => 'TIMESTAMP', 'null' => true],
            'started_at'        => ['type' => 'TIMESTAMP', 'null' => true],
            'finished_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'requested_by'      => ['type' => 'BIGINT', 'null' => true],
            'requester_kind'    => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'user'],
            'report_id'         => ['type' => 'BIGINT', 'null' => true],
            'approval_id'       => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('action');
        $this->forge->addKey('status');
        $this->forge->addKey('run_at');
        $this->forge->createTable('engage_bot_queue', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_bot_queue', true);
    }
}

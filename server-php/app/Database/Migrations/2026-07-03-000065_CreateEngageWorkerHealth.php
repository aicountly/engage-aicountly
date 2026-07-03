<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageWorkerHealth extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'job_kind'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false], // screenshot | review | run
            'status'         => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'success'],
            'http_status'    => ['type' => 'INTEGER', 'null' => true],
            'latency_ms'     => ['type' => 'INTEGER', 'null' => true],
            'error_message'  => ['type' => 'TEXT', 'null' => true],
            'metadata'       => ['type' => 'JSONB', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('job_kind');
        $this->forge->addKey('created_at');
        $this->forge->createTable('engage_worker_health', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_worker_health', true);
    }
}

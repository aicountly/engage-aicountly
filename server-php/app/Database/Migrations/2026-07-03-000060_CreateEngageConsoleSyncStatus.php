<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageConsoleSyncStatus extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'event_kind'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],  // audit | approval_request | approval_decision | execution_report | mode_status | health
            'correlation_id'    => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'direction'         => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'outbound'], // outbound | inbound
            'http_status'       => ['type' => 'INTEGER', 'null' => true],
            'success'           => ['type' => 'BOOLEAN', 'default' => false],
            'error_message'     => ['type' => 'TEXT', 'null' => true],
            'request_payload'   => ['type' => 'JSONB', 'null' => true],
            'response_payload'  => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('event_kind');
        $this->forge->addKey('success');
        $this->forge->addKey('created_at');
        $this->forge->createTable('engage_console_sync_status', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_console_sync_status', true);
    }
}

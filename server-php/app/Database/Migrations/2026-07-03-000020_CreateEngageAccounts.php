<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageAccounts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'legal_name'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'website'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'industry'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'size_bucket'    => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'country'        => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'city'           => ['type' => 'VARCHAR', 'constraint' => 96,  'null' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 32,  'default' => 'prospect'],
            'owner_id'       => ['type' => 'BIGINT', 'null' => true],
            'notes'          => ['type' => 'TEXT', 'null' => true],
            'metadata'       => ['type' => 'JSONB', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('name');
        $this->forge->addKey('status');
        $this->forge->addKey('owner_id');
        $this->forge->createTable('engage_accounts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_accounts', true);
    }
}

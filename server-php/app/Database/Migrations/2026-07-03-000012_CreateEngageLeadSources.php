<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageLeadSources extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => false],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'source_type'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'source_portal'  => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'default_weight' => ['type' => 'INTEGER', 'default' => 10],
            'is_active'      => ['type' => 'BOOLEAN', 'default' => true],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('source_type');
        $this->forge->createTable('engage_lead_sources', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_lead_sources', true);
    }
}

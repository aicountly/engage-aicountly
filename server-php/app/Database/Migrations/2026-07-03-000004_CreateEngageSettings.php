<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'key'         => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => false],
            'value_json'  => ['type' => 'JSONB', 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'updated_by'  => ['type' => 'BIGINT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('key');
        $this->forge->createTable('engage_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_settings', true);
    }
}

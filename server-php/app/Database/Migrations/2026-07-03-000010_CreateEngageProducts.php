<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageProducts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'code'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'is_active'   => ['type' => 'BOOLEAN', 'default' => true],
            'sort_order'  => ['type' => 'INTEGER', 'default' => 0],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('engage_products', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_products', true);
    }
}

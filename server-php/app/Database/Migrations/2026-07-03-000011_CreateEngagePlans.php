<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngagePlans extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGSERIAL'],
            'code'               => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'product_id'         => ['type' => 'BIGINT', 'null' => true],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'billing_cycle'      => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'monthly'],
            'base_price'         => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'user_included'      => ['type' => 'INTEGER', 'default' => 1],
            'company_included'   => ['type' => 'INTEGER', 'default' => 1],
            'features'           => ['type' => 'JSONB', 'null' => true],
            'is_active'          => ['type' => 'BOOLEAN', 'default' => true],
            'description'        => ['type' => 'TEXT', 'null' => true],
            'created_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('product_id');
        $this->forge->addForeignKey('product_id', 'engage_products', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_plans', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_plans', true);
    }
}

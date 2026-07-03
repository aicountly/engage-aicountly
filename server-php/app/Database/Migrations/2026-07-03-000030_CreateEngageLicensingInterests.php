<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageLicensingInterests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGSERIAL'],
            'lead_id'            => ['type' => 'BIGINT', 'null' => true],
            'account_id'         => ['type' => 'BIGINT', 'null' => true],
            'product_id'         => ['type' => 'BIGINT', 'null' => true],
            'plan_id'            => ['type' => 'BIGINT', 'null' => true],
            'expected_users'     => ['type' => 'INTEGER', 'null' => true],
            'expected_companies' => ['type' => 'INTEGER', 'null' => true],
            'requested_start'    => ['type' => 'DATE', 'null' => true],
            'billing_cycle'      => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'monthly'],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'open'],
            'notes'              => ['type' => 'TEXT', 'null' => true],
            'metadata'           => ['type' => 'JSONB', 'null' => true],
            'created_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('account_id');
        $this->forge->addKey('product_id');
        $this->forge->addKey('plan_id');
        $this->forge->addForeignKey('lead_id',    'engage_leads',    'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'engage_accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'engage_products', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('plan_id',    'engage_plans',    'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_licensing_interests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_licensing_interests', true);
    }
}

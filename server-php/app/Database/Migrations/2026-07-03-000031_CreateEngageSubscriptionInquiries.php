<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageSubscriptionInquiries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGSERIAL'],
            'lead_id'            => ['type' => 'BIGINT', 'null' => true],
            'account_id'         => ['type' => 'BIGINT', 'null' => true],
            'product_id'         => ['type' => 'BIGINT', 'null' => true],
            'plan_id'            => ['type' => 'BIGINT', 'null' => true],
            'billing_cycle'      => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'monthly'],
            'qty_users'          => ['type' => 'INTEGER', 'default' => 1],
            'qty_companies'      => ['type' => 'INTEGER', 'default' => 1],
            'target_amount'      => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'inquiry'],
            'convert_placeholder'=> ['type' => 'BOOLEAN', 'default' => false],
            'notes'              => ['type' => 'TEXT', 'null' => true],
            'metadata'           => ['type' => 'JSONB', 'null' => true],
            'created_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('account_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('lead_id',    'engage_leads',    'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'engage_accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'engage_products', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('plan_id',    'engage_plans',    'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_subscription_inquiries', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_subscription_inquiries', true);
    }
}

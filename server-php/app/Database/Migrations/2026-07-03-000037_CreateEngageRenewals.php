<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageRenewals extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'account_id'        => ['type' => 'BIGINT', 'null' => true],
            'lead_id'           => ['type' => 'BIGINT', 'null' => true],
            'product_id'        => ['type' => 'BIGINT', 'null' => true],
            'plan_id'           => ['type' => 'BIGINT', 'null' => true],
            'external_ref'      => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'current_end_date'  => ['type' => 'DATE', 'null' => true],
            'next_renewal_date' => ['type' => 'DATE', 'null' => true],
            'target_amount'     => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'currency'          => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'upcoming'],
            'reminder_stage'    => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'owner_id'          => ['type' => 'BIGINT', 'null' => true],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'metadata'          => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('account_id');
        $this->forge->addKey('next_renewal_date');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('account_id', 'engage_accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('lead_id',    'engage_leads',    'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'engage_products', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('plan_id',    'engage_plans',    'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_renewals', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_renewals', true);
    }
}

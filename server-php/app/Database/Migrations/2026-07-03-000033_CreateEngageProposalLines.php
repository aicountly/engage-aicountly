<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageProposalLines extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'proposal_id'       => ['type' => 'BIGINT', 'null' => false],
            'product_id'        => ['type' => 'BIGINT', 'null' => true],
            'plan_id'           => ['type' => 'BIGINT', 'null' => true],
            'sort_order'        => ['type' => 'INTEGER', 'default' => 0],
            'description'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'quantity'          => ['type' => 'NUMERIC', 'constraint' => '12,2', 'default' => 1],
            'unit_price'        => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'discount_percent'  => ['type' => 'NUMERIC', 'constraint' => '5,2', 'default' => 0],
            'line_total'        => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('proposal_id');
        $this->forge->addForeignKey('proposal_id', 'engage_proposals', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('product_id',  'engage_products',  'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('plan_id',     'engage_plans',     'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_proposal_lines', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_proposal_lines', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageDiscountRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'lead_id'           => ['type' => 'BIGINT', 'null' => true],
            'proposal_id'       => ['type' => 'BIGINT', 'null' => true],
            'plan_id'           => ['type' => 'BIGINT', 'null' => true],
            'requested_by'      => ['type' => 'BIGINT', 'null' => true],
            'requester_kind'    => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'user'],
            'discount_percent'  => ['type' => 'NUMERIC', 'constraint' => '5,2', 'default' => 0],
            'discount_amount'   => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'currency'          => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'justification'     => ['type' => 'TEXT', 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'approval_id'       => ['type' => 'BIGINT', 'null' => true],
            'decided_by'        => ['type' => 'BIGINT', 'null' => true],
            'decided_at'        => ['type' => 'TIMESTAMP', 'null' => true],
            'decision_notes'    => ['type' => 'TEXT', 'null' => true],
            'metadata'          => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('proposal_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('lead_id',     'engage_leads',     'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('proposal_id', 'engage_proposals', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('plan_id',     'engage_plans',     'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_discount_requests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_discount_requests', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageCreditLedger extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'party_type'        => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false],  // lead | customer | affiliate | internal
            'party_reference'   => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => false],
            'credit_type'       => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => false],  // lead_credit | wallet | reward | commission | subscription_credit | affiliate_points | invoice_credit | adjustment
            'direction'         => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => false],   // debit | credit
            'amount'            => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'points_amount'     => ['type' => 'NUMERIC', 'constraint' => '14,4', 'default' => 0],
            'currency'          => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'points_unit'       => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'source'            => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'linked_kind'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],   // lead | proposal | subscription | renewal | invoice | other
            'linked_id'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'posted'], // draft | pending_approval | posted | reversed
            'approval_id'       => ['type' => 'BIGINT', 'null' => true],
            'remarks'           => ['type' => 'TEXT', 'null' => true],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            'creator_kind'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'user'],
            'approved_by'       => ['type' => 'BIGINT', 'null' => true],
            'approved_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'metadata'          => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('party_type');
        $this->forge->addKey('party_reference');
        $this->forge->addKey('credit_type');
        $this->forge->addKey('status');
        $this->forge->addKey(['linked_kind', 'linked_id']);
        $this->forge->createTable('engage_credit_ledger', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_credit_ledger', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageProposals extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'proposal_code'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'lead_id'           => ['type' => 'BIGINT', 'null' => true],
            'account_id'        => ['type' => 'BIGINT', 'null' => true],
            'contact_id'        => ['type' => 'BIGINT', 'null' => true],
            'title'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'summary'           => ['type' => 'TEXT', 'null' => true],
            'currency'          => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'total_amount'      => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'discount_amount'   => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'net_amount'        => ['type' => 'NUMERIC', 'constraint' => '14,2', 'default' => 0],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'draft'],
            'valid_until'       => ['type' => 'DATE', 'null' => true],
            'sent_at'           => ['type' => 'TIMESTAMP', 'null' => true],
            'accepted_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'declined_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'owner_id'          => ['type' => 'BIGINT', 'null' => true],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'metadata'          => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('proposal_code');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('account_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('lead_id',    'engage_leads',    'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'engage_accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'engage_contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_proposals', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_proposals', true);
    }
}

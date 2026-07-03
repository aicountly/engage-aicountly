<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageCommunicationDrafts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGSERIAL'],
            'lead_id'       => ['type' => 'BIGINT', 'null' => true],
            'proposal_id'   => ['type' => 'BIGINT', 'null' => true],
            'channel'       => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false], // email | whatsapp | sms
            'to_address'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'subject'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'body'          => ['type' => 'TEXT', 'null' => true],
            'attachments'   => ['type' => 'JSONB', 'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'approval_id'   => ['type' => 'BIGINT', 'null' => true],
            'created_by'    => ['type' => 'BIGINT', 'null' => true],
            'creator_kind'  => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'user'],
            'approved_by'   => ['type' => 'BIGINT', 'null' => true],
            'approved_at'   => ['type' => 'TIMESTAMP', 'null' => true],
            'sent_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'metadata'      => ['type' => 'JSONB', 'null' => true],
            'created_at'    => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'    => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('lead_id',     'engage_leads',     'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('proposal_id', 'engage_proposals', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_communication_drafts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_communication_drafts', true);
    }
}

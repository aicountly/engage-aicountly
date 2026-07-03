<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageFollowUps extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'lead_id'     => ['type' => 'BIGINT', 'null' => true],
            'proposal_id' => ['type' => 'BIGINT', 'null' => true],
            'renewal_id'  => ['type' => 'BIGINT', 'null' => true],
            'due_at'      => ['type' => 'TIMESTAMP', 'null' => false],
            'kind'        => ['type' => 'VARCHAR', 'constraint' => 24,  'default' => 'call'],
            'channel'     => ['type' => 'VARCHAR', 'constraint' => 24,  'default' => 'phone'],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'body'        => ['type' => 'TEXT', 'null' => true],
            'owner_id'    => ['type' => 'BIGINT', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'completed_at'=> ['type' => 'TIMESTAMP', 'null' => true],
            'outcome'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'created_by_kind' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'user'],
            'metadata'    => ['type' => 'JSONB', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('due_at');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('lead_id',     'engage_leads',     'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('proposal_id', 'engage_proposals', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_follow_ups', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_follow_ups', true);
    }
}

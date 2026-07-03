<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageLeadActivities extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGSERIAL'],
            'lead_id'        => ['type' => 'BIGINT', 'null' => false],
            'activity_type'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'title'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'body'           => ['type' => 'TEXT', 'null' => true],
            'author_id'      => ['type' => 'BIGINT', 'null' => true],
            'author_kind'    => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'user'],
            'metadata'       => ['type' => 'JSONB', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('lead_id');
        $this->forge->addKey('activity_type');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('lead_id', 'engage_leads', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('engage_lead_activities', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_lead_activities', true);
    }
}

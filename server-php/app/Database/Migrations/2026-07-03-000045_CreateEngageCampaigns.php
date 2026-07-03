<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageCampaigns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGSERIAL'],
            'external_code'     => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'source_portal'     => ['type' => 'VARCHAR', 'constraint' => 96, 'null' => true],
            'campaign_kind'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'active'],
            'started_at'        => ['type' => 'TIMESTAMP', 'null' => true],
            'ended_at'          => ['type' => 'TIMESTAMP', 'null' => true],
            'metadata'          => ['type' => 'JSONB', 'null' => true],
            'created_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'        => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['source_portal', 'external_code']);
        $this->forge->addKey('name');
        $this->forge->createTable('engage_campaigns', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_campaigns', true);
    }
}

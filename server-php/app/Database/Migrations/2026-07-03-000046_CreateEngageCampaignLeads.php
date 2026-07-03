<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageCampaignLeads extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'campaign_id' => ['type' => 'BIGINT', 'null' => false],
            'lead_id'     => ['type' => 'BIGINT', 'null' => false],
            'attribution' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'primary'],
            'metadata'    => ['type' => 'JSONB', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['campaign_id', 'lead_id']);
        $this->forge->addForeignKey('campaign_id', 'engage_campaigns', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('lead_id',     'engage_leads',     'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('engage_campaign_leads', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_campaign_leads', true);
    }
}

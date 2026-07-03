<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageLeads extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'lead_code'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'organization'          => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'account_id'            => ['type' => 'BIGINT', 'null' => true],
            'contact_id'            => ['type' => 'BIGINT', 'null' => true],
            'email'                 => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'mobile'                => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'whatsapp'               => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'source_portal'         => ['type' => 'VARCHAR', 'constraint' => 96,  'null' => true],
            'source_campaign'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'source_type'           => ['type' => 'VARCHAR', 'constraint' => 32,  'default' => 'other'],
            'lead_source_id'        => ['type' => 'BIGINT', 'null' => true],
            'interested_product'    => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => true],
            'interested_product_id' => ['type' => 'BIGINT', 'null' => true],
            'subscription_interest' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'expected_users'        => ['type' => 'INTEGER', 'null' => true],
            'expected_companies'    => ['type' => 'INTEGER', 'null' => true],
            'lead_score'            => ['type' => 'INTEGER', 'default' => 0],
            'stage'                 => ['type' => 'VARCHAR', 'constraint' => 64,  'default' => 'new'],
            'priority'              => ['type' => 'VARCHAR', 'constraint' => 16,  'default' => 'normal'],
            'owner_id'              => ['type' => 'BIGINT', 'null' => true],
            'sales_status'          => ['type' => 'VARCHAR', 'constraint' => 32,  'default' => 'open'],
            'next_follow_up_at'     => ['type' => 'TIMESTAMP', 'null' => true],
            'last_contacted_at'     => ['type' => 'TIMESTAMP', 'null' => true],
            'conversion_probability'=> ['type' => 'INTEGER', 'default' => 0],
            'notes'                 => ['type' => 'TEXT', 'null' => true],
            'bot_summary'           => ['type' => 'TEXT', 'null' => true],
            'metadata'              => ['type' => 'JSONB', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('lead_code');
        $this->forge->addKey('email');
        $this->forge->addKey('stage');
        $this->forge->addKey('source_type');
        $this->forge->addKey('interested_product');
        $this->forge->addKey('owner_id');
        $this->forge->addKey('lead_score');
        $this->forge->addKey('priority');
        $this->forge->addKey('next_follow_up_at');
        $this->forge->addForeignKey('account_id', 'engage_accounts',    'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'engage_contacts',    'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('lead_source_id', 'engage_lead_sources', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('interested_product_id', 'engage_products', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_leads', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_leads', true);
    }
}

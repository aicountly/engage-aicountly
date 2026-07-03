<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageBotActions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGSERIAL'],
            'code'               => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'category'           => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'general'],
            'risk_level'         => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'low'],
            'default_approval'   => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'required'],
            'is_auto_eligible'   => ['type' => 'BOOLEAN', 'default' => false],
            'description'        => ['type' => 'TEXT', 'null' => true],
            'created_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('engage_bot_actions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_bot_actions', true);
    }
}

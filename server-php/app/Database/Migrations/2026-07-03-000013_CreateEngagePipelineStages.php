<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngagePipelineStages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGSERIAL'],
            'code'                  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'sort_order'            => ['type' => 'INTEGER', 'default' => 0],
            'is_terminal'           => ['type' => 'BOOLEAN', 'default' => false],
            'is_won'                => ['type' => 'BOOLEAN', 'default' => false],
            'is_lost'               => ['type' => 'BOOLEAN', 'default' => false],
            'default_probability'   => ['type' => 'INTEGER', 'default' => 0],
            'colour'                => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'description'           => ['type' => 'TEXT', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('sort_order');
        $this->forge->createTable('engage_pipeline_stages', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_pipeline_stages', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageContacts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGSERIAL'],
            'account_id'  => ['type' => 'BIGINT', 'null' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'mobile'      => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'whatsapp'    => ['type' => 'VARCHAR', 'constraint' => 32,  'null' => true],
            'is_primary'  => ['type' => 'BOOLEAN', 'default' => false],
            'notes'       => ['type' => 'TEXT', 'null' => true],
            'metadata'    => ['type' => 'JSONB', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('account_id');
        $this->forge->addKey('email');
        $this->forge->addForeignKey('account_id', 'engage_accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('engage_contacts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_contacts', true);
    }
}

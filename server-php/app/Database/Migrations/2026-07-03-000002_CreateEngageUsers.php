<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGSERIAL'],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
            'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'active'],
            'last_login_at'   => ['type' => 'TIMESTAMP', 'null' => true],
            'last_login_ip'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'failed_attempts' => ['type' => 'INTEGER', 'default' => 0],
            'created_at'      => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'      => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('engage_users', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_users', true);
    }
}

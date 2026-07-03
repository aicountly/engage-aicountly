<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateEngageUserRoles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGSERIAL'],
            'user_id'    => ['type' => 'BIGINT', 'null' => false],
            'role_id'    => ['type' => 'BIGINT', 'null' => false],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['user_id', 'role_id']);
        $this->forge->addForeignKey('user_id', 'engage_users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('role_id', 'engage_roles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('engage_user_roles', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('engage_user_roles', true);
    }
}

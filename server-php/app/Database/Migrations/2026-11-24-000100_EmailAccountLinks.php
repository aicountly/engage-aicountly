<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class EmailAccountLinks extends Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE engage_email_account_links (
            link_id VARCHAR(32) PRIMARY KEY,
            source_subject VARCHAR(64) NOT NULL UNIQUE,
            source_realm VARCHAR(16) NOT NULL CHECK (source_realm IN ('personal','business')),
            main_subject VARCHAR(64) NOT NULL,
            receipt JSONB NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            CHECK (source_subject <> main_subject))");
        $this->db->query('CREATE INDEX engage_email_links_main ON engage_email_account_links(main_subject)');
        // Preserve the existing event vocabulary and add an explicit ownership event.
        $old = $this->db->query("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'engage_licence_events'::regclass AND conname = 'engage_licence_events_type_chk'")->getRowArray()['definition'];
        $this->db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT engage_licence_events_type_chk');
        $expression = substr($old, 6);
        $this->db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_type_chk CHECK ({$expression} OR type = 'holder_changed')");
    }
    public function down()
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            $this->db->query('DROP TABLE IF EXISTS engage_email_account_links');
            return;
        }
        // A completed licence transfer is not reversed by rolling application code back.
        throw new \RuntimeException('Email account links retain financial audit history; reconcile transfers before a schema rollback.');
    }
}

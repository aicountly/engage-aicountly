<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Outbox for Engage's central audit to Console (ConsoleAuditOutbox →
 * POST {CONSOLE_API_URL}/portal/audit, console-react-app migration 067).
 *
 * Engage used to send its audit fan-out to Console's portal/bot-audit as {portal, event, …};
 * Console's ingest requires event_type, so every event was refused with 422. Each event is now
 * written here first, sent at once, and retried with back-off by
 * `php spark engage:console-audit-deliver` until Console answers stored / duplicate (→ delivered,
 * event cleared) or rejected (kept, not retried); 'dead' after 12 attempts.
 */
class CreateEngageConsoleAuditOutbox extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS engage_console_audit_outbox (
            id               BIGSERIAL     PRIMARY KEY,
            external_id      VARCHAR(128)  NOT NULL UNIQUE,
            event            JSONB         NULL,
            status           VARCHAR(16)   NOT NULL DEFAULT 'pending',
            attempts         INT           NOT NULL DEFAULT 0,
            next_attempt_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            last_http_status INT           NULL,
            last_error       TEXT          NULL,
            console_audit_id BIGINT        NULL,
            created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            delivered_at     TIMESTAMPTZ   NULL,
            CONSTRAINT engage_console_audit_outbox_status_chk CHECK (status IN ('pending','delivered','rejected','dead'))
        )");
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_engage_console_audit_outbox_due
            ON engage_console_audit_outbox (next_attempt_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS engage_console_audit_outbox');
    }
}

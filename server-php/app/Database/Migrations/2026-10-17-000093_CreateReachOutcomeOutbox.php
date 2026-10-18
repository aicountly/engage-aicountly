<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Outbox for lead outcome events Engage sends to Reach
 * (ReachOutcomeOutbox → POST {REACH_API_BASE_URL}/v1/inbound/engage/lead-outcomes, header X-Engage-Token).
 *
 * Same pattern as engage_console_audit_outbox: queue first, send at once, retry with back-off from
 * `php spark engage:reach-outcomes-deliver` (every 5 minutes) until Reach answers stored / duplicate
 * (delivered) or rejected (kept, not retried); 'dead' after 12 attempts. Reach de-duplicates on event_id.
 */
class CreateReachOutcomeOutbox extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS engage_reach_outcome_outbox (
            id               BIGSERIAL     PRIMARY KEY,
            event_id         VARCHAR(64)   NOT NULL UNIQUE,
            lead_id          BIGINT        NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            stage            VARCHAR(32)   NOT NULL,
            event            JSONB         NULL,
            status           VARCHAR(16)   NOT NULL DEFAULT 'pending',
            attempts         INT           NOT NULL DEFAULT 0,
            next_attempt_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            last_http_status INT           NULL,
            last_error       TEXT          NULL,
            created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            delivered_at     TIMESTAMPTZ   NULL,
            CONSTRAINT engage_reach_outcome_outbox_status_chk CHECK (status IN ('pending','delivered','rejected','dead'))
        )");
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_engage_reach_outcome_outbox_due
            ON engage_reach_outcome_outbox (next_attempt_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS engage_reach_outcome_outbox');
    }
}

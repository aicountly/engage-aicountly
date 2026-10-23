<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Engage → Manage entitlement push (contract §3.3; App\Services\Licensing\EntitlementOutbox).
 *
 *   engage_entitlement_seq      the per-subject sequence counter. A subject is a holder ("holder:<uuid>"), or a company
 *                               for a legacy company-only licence ("cmp:<id>"). seq is monotonic per subject and is taken
 *                               under this row's lock, inside the licence write's transaction.
 *   engage_entitlement_outbox   one row per pushed state: the FULL current state of one (subject, app_key), written
 *                               in the same transaction as the licence change. Delivered by
 *                               `php spark engage:entitlements-push` (every minute) strictly in seq order per subject;
 *                               back-off 2, 4, 8 … minutes (at most 6 h), `dead` after 12 attempts; a 4xx Manage
 *                               refuses for good is `rejected`. `skipped` = a newer state of the same (subject, app)
 *                               was queued before this one was sent (states are full, so only the newest matters).
 */
class CreateEntitlementOutbox extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $db->query("CREATE TABLE IF NOT EXISTS engage_entitlement_seq (
            subject_key VARCHAR(96) PRIMARY KEY,
            last_seq    BIGINT      NOT NULL
        )");
        $db->query("CREATE TABLE IF NOT EXISTS engage_entitlement_outbox (
            id               BIGSERIAL    PRIMARY KEY,
            event_id         VARCHAR(40)  NOT NULL UNIQUE,
            subject_key      VARCHAR(96)  NOT NULL,
            holder_uuid      VARCHAR(64)  NULL,
            cmp_id           BIGINT       NULL,
            app_key          VARCHAR(48)  NOT NULL,
            seq              BIGINT       NOT NULL,
            reason           VARCHAR(48)  NOT NULL,
            licence_ref      VARCHAR(32)  NULL,
            payload          JSON         NOT NULL,
            state_hash       CHAR(64)     NOT NULL,
            status           VARCHAR(12)  NOT NULL DEFAULT 'pending',
            attempts         INT          NOT NULL DEFAULT 0,
            next_attempt_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            last_http_status INT          NULL,
            last_error       TEXT         NULL,
            created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            delivered_at     TIMESTAMPTZ  NULL,
            UNIQUE (subject_key, seq),
            CONSTRAINT engage_entitlement_outbox_status_chk CHECK (status IN ('pending','delivered','rejected','dead','skipped')),
            CONSTRAINT engage_entitlement_outbox_subject_chk CHECK (holder_uuid IS NOT NULL OR cmp_id IS NOT NULL)
        )");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_entitlement_outbox_due ON engage_entitlement_outbox (next_attempt_at) WHERE status = 'pending'");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_entitlement_outbox_subject ON engage_entitlement_outbox (subject_key, app_key, seq DESC)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_entitlement_outbox_licence ON engage_entitlement_outbox (licence_ref) WHERE licence_ref IS NOT NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS engage_entitlement_outbox');
        $this->db->query('DROP TABLE IF EXISTS engage_entitlement_seq');
    }
}

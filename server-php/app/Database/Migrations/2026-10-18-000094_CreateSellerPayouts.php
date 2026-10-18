<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Weekly seller payouts (docs/SELLER_PROGRAM.md § 6). Engage only compiles DRAFTS and hands them to
 * finance.aicountly.org; a person in Finance reviews and finalises them. Engage marks a payout paid only
 * when Finance reports `paid` with a UTR.
 *
 *   engage_payout_runs               one per ISO week (Monday 00:00 – Sunday 23:59 IST): what the run collected,
 *                                    its status and error. A run that failed (nothing written) may be run again;
 *                                    any other run of that week is final (idempotent per week).
 *   engage_seller_payouts            one per seller per week (payout_ref = Finance item_ref, PO-YYYY-WW-NNNNNN):
 *                                    gross, the commission entry ids, Finance's TDS / GST / net / UTR / paid_at.
 *   engage_seller_payout_events      every status Finance reported (and Engage's own transitions), for the
 *                                    staff timeline and callback idempotency (payload_sha256).
 *   engage_finance_payout_outbox     one row per batch: delivery state of POST finance /v1/inbound/engage/payout-batches.
 *                                    The payload is NOT stored: payee PAN / bank numbers are decrypted from KYC
 *                                    only while the request body is built.
 */
class CreateSellerPayouts extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query("CREATE TABLE IF NOT EXISTS engage_payout_runs (
            id                   BIGSERIAL     PRIMARY KEY,
            iso_week             VARCHAR(8)    NOT NULL UNIQUE,
            batch_ref            VARCHAR(24)   NOT NULL UNIQUE,
            period_start         DATE          NOT NULL,
            period_end           DATE          NOT NULL,
            cutoff_at            TIMESTAMPTZ   NOT NULL,
            status               VARCHAR(16)   NOT NULL DEFAULT 'running',
            trigger_source       VARCHAR(16)   NOT NULL DEFAULT 'schedule',
            triggered_by         BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            min_payout           NUMERIC(14,2) NOT NULL DEFAULT 500.00,
            sellers_considered   INTEGER       NOT NULL DEFAULT 0,
            payouts_count        INTEGER       NOT NULL DEFAULT 0,
            entries_count        INTEGER       NOT NULL DEFAULT 0,
            gross_amount         NUMERIC(14,2) NOT NULL DEFAULT 0,
            below_minimum_count  INTEGER       NOT NULL DEFAULT 0,
            below_minimum_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
            ineligible_count     INTEGER       NOT NULL DEFAULT 0,
            ineligible_amount    NUMERIC(14,2) NOT NULL DEFAULT 0,
            finance_batch_id     VARCHAR(96)   NULL,
            error                TEXT          NULL,
            attempts             INTEGER       NOT NULL DEFAULT 1,
            started_at           TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            finished_at          TIMESTAMPTZ   NULL,
            sent_at              TIMESTAMPTZ   NULL,
            cancelled_at         TIMESTAMPTZ   NULL,
            cancelled_by         BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at           TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            updated_at           TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_payout_runs_status_chk CHECK (status IN ('running','empty','queued','sent','delivery_failed','failed','cancelled')),
            CONSTRAINT engage_payout_runs_trigger_chk CHECK (trigger_source IN ('schedule','manual'))
        )");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_payouts (
            id                BIGSERIAL     PRIMARY KEY,
            payout_ref        VARCHAR(32)   NOT NULL UNIQUE,
            item_ref          VARCHAR(32)   NOT NULL UNIQUE,
            run_id            BIGINT        NOT NULL REFERENCES engage_payout_runs(id) ON DELETE RESTRICT,
            batch_ref         VARCHAR(24)   NOT NULL,
            seller_id         BIGINT        NOT NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT,
            seller_code       VARCHAR(16)   NOT NULL,
            iso_week          VARCHAR(8)    NOT NULL,
            period_start      DATE          NOT NULL,
            period_end        DATE          NOT NULL,
            currency          VARCHAR(3)    NOT NULL DEFAULT 'INR',
            gross_amount      NUMERIC(14,2) NOT NULL,
            entries_count     INTEGER       NOT NULL,
            entry_ids         JSONB         NOT NULL,
            status            VARCHAR(16)   NOT NULL DEFAULT 'queued',
            tds_section       VARCHAR(16)   NULL,
            tds_amount        NUMERIC(14,2) NULL,
            gst_amount        NUMERIC(14,2) NULL,
            net_amount        NUMERIC(14,2) NULL,
            utr               VARCHAR(64)   NULL,
            paid_at           TIMESTAMPTZ   NULL,
            reason            TEXT          NULL,
            finance_batch_ref VARCHAR(96)   NULL,
            sent_at           TIMESTAMPTZ   NULL,
            finance_status_at TIMESTAMPTZ   NULL,
            created_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            updated_at        TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            UNIQUE (seller_id, iso_week),
            CONSTRAINT engage_seller_payouts_status_chk CHECK (status IN ('queued','draft_sent','on_hold','approved','paid','failed','reversed','cancelled')),
            CONSTRAINT engage_seller_payouts_gross_chk CHECK (gross_amount > 0),
            CONSTRAINT engage_seller_payouts_paid_utr_chk CHECK (status <> 'paid' OR (utr IS NOT NULL AND utr <> ''))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_payouts_seller ON engage_seller_payouts (seller_id, period_start DESC)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_payouts_run ON engage_seller_payouts (run_id)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_payout_events (
            id             BIGSERIAL    PRIMARY KEY,
            payout_id      BIGINT       NOT NULL REFERENCES engage_seller_payouts(id) ON DELETE CASCADE,
            source         VARCHAR(16)  NOT NULL,
            from_status    VARCHAR(16)  NULL,
            to_status      VARCHAR(16)  NOT NULL,
            payload        JSONB        NULL,
            payload_sha256 CHAR(64)     NULL,
            created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_payout_events_source_chk CHECK (source IN ('engage','finance'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_payout_events_payout ON engage_seller_payout_events (payout_id, id)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_finance_payout_outbox (
            id               BIGSERIAL     PRIMARY KEY,
            run_id           BIGINT        NOT NULL UNIQUE REFERENCES engage_payout_runs(id) ON DELETE CASCADE,
            batch_ref        VARCHAR(24)   NOT NULL UNIQUE,
            status           VARCHAR(16)   NOT NULL DEFAULT 'pending',
            attempts         INTEGER       NOT NULL DEFAULT 0,
            next_attempt_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            last_http_status INTEGER       NULL,
            last_error       TEXT          NULL,
            finance_batch_id VARCHAR(96)   NULL,
            created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            delivered_at     TIMESTAMPTZ   NULL,
            CONSTRAINT engage_finance_payout_outbox_status_chk CHECK (status IN ('pending','delivered','rejected','dead','cancelled'))
        )");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_finance_payout_outbox_due
            ON engage_finance_payout_outbox (next_attempt_at) WHERE status = 'pending'");

        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_commission_entries_payout ON engage_commission_entries (payout_item_ref) WHERE payout_item_ref IS NOT NULL");
        $db->query("UPDATE engage_settings SET description = ?, updated_at = CURRENT_TIMESTAMP WHERE key = 'seller_payout_min_threshold'",
            ['Minimum weekly payout per seller in INR. A seller whose approved commission is below it is skipped that week; the amount rolls over to a later run.']);
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_engage_commission_entries_payout');
        $this->db->query('DROP TABLE IF EXISTS engage_finance_payout_outbox');
        $this->db->query('DROP TABLE IF EXISTS engage_seller_payout_events');
        $this->db->query('DROP TABLE IF EXISTS engage_seller_payouts');
        $this->db->query('DROP TABLE IF EXISTS engage_payout_runs');
    }
}

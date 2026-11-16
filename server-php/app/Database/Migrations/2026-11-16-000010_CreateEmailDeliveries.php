<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Mail Engage sends through the AICOUNTLY Email delivery API, and the signed events about it
 * (Services\Email\EmailDeliveryLedger, DeliveryWebhook). Ids, states and DSN codes only — no content.
 *
 *   engage_email_deliveries       one row per accepted message: idempotency_key (unique, what Engage sent),
 *                                 delivery_id (the Email API's id), category, cmp_id, summary status and
 *                                 per-recipient state {address: {status, dsn, bounce_class, at}}
 *   engage_email_delivery_events  each webhook event once; event_id is the dedupe key
 */
class CreateEmailDeliveries extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query("CREATE TABLE IF NOT EXISTS engage_email_deliveries (
            id               BIGSERIAL    PRIMARY KEY,
            idempotency_key  VARCHAR(160) NOT NULL UNIQUE,
            delivery_id      VARCHAR(64)  NULL,
            category         VARCHAR(16)  NOT NULL,
            cmp_id           BIGINT       NULL,
            template_ref     VARCHAR(120) NULL,
            recipient_count  INTEGER      NOT NULL DEFAULT 0,
            recipients       JSONB        NOT NULL DEFAULT '{}'::jsonb,
            status           VARCHAR(16)  NOT NULL DEFAULT 'queued',
            last_event       VARCHAR(16)  NULL,
            last_event_at    TIMESTAMPTZ  NULL,
            created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_email_deliveries_delivery_id ON engage_email_deliveries (delivery_id)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_email_deliveries_status ON engage_email_deliveries (status, updated_at)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_email_delivery_events (
            event_id         BIGINT       PRIMARY KEY,
            event            VARCHAR(16)  NOT NULL,
            delivery_id      VARCHAR(64)  NULL,
            idempotency_key  VARCHAR(160) NULL,
            category         VARCHAR(16)  NULL,
            recipient        VARCHAR(254) NULL,
            dsn              VARCHAR(16)  NULL,
            bounce_class     VARCHAR(32)  NULL,
            occurred_at      TIMESTAMPTZ  NULL,
            payload          JSONB        NOT NULL,
            received_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_email_delivery_events_delivery ON engage_email_delivery_events (delivery_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS engage_email_delivery_events');
        $this->db->query('DROP TABLE IF EXISTS engage_email_deliveries');
    }
}

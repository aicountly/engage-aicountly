<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Seller commissions (bookkeeping only — payouts are not built yet, see docs/SELLER_PROGRAM.md).
 *
 *   engage_commission_plans    versioned plans: draft → active → retired. rules JSON
 *                              (percent_of_first_payment | percent_recurring_months | flat_per_conversion | slab),
 *                              product_scope JSON (list of product codes, or ["*"]), hold_days (default 15).
 *                              None is shipped active: nothing accrues until an admin publishes a plan.
 *   engage_lead_payments       customer payments a sales manager confirmed in Engage ("payment confirmed").
 *                              Each payment is evaluated for commission exactly once (commission_evaluated_at):
 *                              when it is confirmed on a converted lead, or when its lead converts.
 *                              At most one commission entry per payment.
 *   engage_commission_entries  accrued → approved (after hold_days) → in_payout → paid | reversed.
 *                              Linked to the lead, the payment, the product, the amount basis and the exact plan version.
 *
 * Commission does NOT go through engage_credit_ledger: that ledger is a generic credit/points book
 * (lead / customer / affiliate / internal) with no notion of plan versions, hold windows or payout state.
 * Commission entries are their own ledger; engage_credit_ledger is left unchanged.
 */
class CreateCommissionTables extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query("CREATE TABLE IF NOT EXISTS engage_commission_plans (
            id            BIGSERIAL PRIMARY KEY,
            plan_code     VARCHAR(48)   NOT NULL,
            version       INTEGER       NOT NULL DEFAULT 1,
            name          VARCHAR(191)  NOT NULL,
            description   TEXT          NULL,
            status        VARCHAR(16)   NOT NULL DEFAULT 'draft',
            rules         JSONB         NOT NULL,
            product_scope JSONB         NOT NULL DEFAULT '[\"*\"]'::jsonb,
            hold_days     INTEGER       NOT NULL DEFAULT 15,
            published_at  TIMESTAMPTZ   NULL,
            published_by  BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            retired_at    TIMESTAMPTZ   NULL,
            retired_by    BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_by    BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at    TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            updated_at    TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            UNIQUE (plan_code, version),
            CONSTRAINT engage_commission_plans_status_chk CHECK (status IN ('draft','active','retired')),
            CONSTRAINT engage_commission_plans_hold_chk CHECK (hold_days BETWEEN 0 AND 365)
        )");
        // At most one active version per plan.
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_commission_plans_active
            ON engage_commission_plans (plan_code) WHERE status = 'active'");

        $db->query("CREATE TABLE IF NOT EXISTS engage_lead_payments (
            id            BIGSERIAL PRIMARY KEY,
            lead_id       BIGINT        NOT NULL REFERENCES engage_leads(id) ON DELETE RESTRICT,
            payment_ref   VARCHAR(96)   NOT NULL,
            sequence_no   INTEGER       NOT NULL,
            amount        NUMERIC(14,2) NOT NULL,
            currency      VARCHAR(3)    NOT NULL DEFAULT 'INR',
            product_code  VARCHAR(64)   NULL,
            paid_at       TIMESTAMPTZ   NOT NULL,
            status        VARCHAR(16)   NOT NULL DEFAULT 'confirmed',
            notes         TEXT          NULL,
            confirmed_by  BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            confirmed_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            refunded_at   TIMESTAMPTZ   NULL,
            refunded_by   BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            refund_reason TEXT          NULL,
            commission_evaluated_at TIMESTAMPTZ NULL,
            commission_note         VARCHAR(255) NULL,
            UNIQUE (lead_id, payment_ref),
            CONSTRAINT engage_lead_payments_amount_chk CHECK (amount > 0),
            CONSTRAINT engage_lead_payments_status_chk CHECK (status IN ('confirmed','refunded'))
        )");

        $db->query("CREATE TABLE IF NOT EXISTS engage_commission_entries (
            id               BIGSERIAL PRIMARY KEY,
            seller_id        BIGINT        NOT NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT,
            lead_id          BIGINT        NOT NULL REFERENCES engage_leads(id) ON DELETE RESTRICT,
            payment_id       BIGINT        NOT NULL REFERENCES engage_lead_payments(id) ON DELETE RESTRICT,
            plan_id          BIGINT        NOT NULL REFERENCES engage_commission_plans(id) ON DELETE RESTRICT,
            plan_code        VARCHAR(48)   NOT NULL,
            plan_version     INTEGER       NOT NULL,
            rule_type        VARCHAR(32)   NOT NULL,
            product_code     VARCHAR(64)   NULL,
            basis_amount     NUMERIC(14,2) NOT NULL,
            rate_percent     NUMERIC(9,4)  NULL,
            amount           NUMERIC(14,2) NOT NULL,
            currency         VARCHAR(3)    NOT NULL DEFAULT 'INR',
            status           VARCHAR(16)   NOT NULL DEFAULT 'accrued',
            calculation      JSONB         NULL,
            accrued_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            hold_until       TIMESTAMPTZ   NOT NULL,
            approved_at      TIMESTAMPTZ   NULL,
            payout_item_ref  VARCHAR(64)   NULL,
            paid_at          TIMESTAMPTZ   NULL,
            reversed_at      TIMESTAMPTZ   NULL,
            reversed_by      BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            reversal_reason  TEXT          NULL,
            created_by       BIGINT        NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            updated_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
            UNIQUE (payment_id),
            CONSTRAINT engage_commission_entries_status_chk CHECK (status IN ('accrued','approved','in_payout','paid','reversed')),
            CONSTRAINT engage_commission_entries_amount_chk CHECK (amount >= 0)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_commission_entries_seller ON engage_commission_entries (seller_id, status)');
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_commission_entries_due ON engage_commission_entries (hold_until) WHERE status = 'accrued'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS engage_commission_entries');
        $this->db->query('DROP TABLE IF EXISTS engage_lead_payments');
        $this->db->query('DROP TABLE IF EXISTS engage_commission_plans');
    }
}

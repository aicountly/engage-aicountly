<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Discount coupons (docs/COUPONS.md, shared contract §4.1, founder decision D-C1). Nothing is seeded: no coupon
 * exists until staff create one, so the migration changes nothing for existing Engage users.
 *
 *   engage_coupons             the rule: percent (basis points) or flat paise, caps, scope (applies_to), validity,
 *                              limits, optional seller / campaign. draft → active ⇄ paused → expired | exhausted →
 *                              retired. Trigger engage_coupons_guard: once out of draft the rule is IMMUTABLE except
 *                              its status (and the approval / timestamp columns the status change sets); only a
 *                              draft can be deleted; the allowed status moves are enforced too.
 *   engage_coupon_codes        what a customer / staff types. code is stored normalised (upper case, no spaces or
 *                              hyphens) and UNIQUE → case-insensitive. Single-use codes: available → reserved →
 *                              redeemed (or back to available when a reservation is released); void.
 *   engage_quotes              a priced quote (list, discount, net, GST on net, total) for a holder / company and
 *                              an offer — quote_ref is what staff pass when issuing / renewing a licence.
 *   engage_coupon_redemptions  the ledger, one row per use: reserved → committed | released, committed → reversed
 *                              (trigger engage_coupon_redemptions_guard; money columns immutable; never deleted).
 *                              idempotency_key UNIQUE. A single-use code has at most one live (reserved / committed)
 *                              redemption (partial unique index).
 *   engage_coupon_attempts     every validation attempt (no raw code, only its sha256) — rate limiting and abuse
 *                              reports.
 *
 * Additive changes to existing tables (all nullable):
 *   engage_subscription_inquiries  + request_ref, request_key, source, holder_uuid, cmp_id, offer_code, quote_ref,
 *                                  contact_name / _email / _phone (Manage "Request upgrade")
 *   engage_lead_payments           + quote_ref, list_paise, discount_paise, basis_paise, basis_kind (commission basis
 *                                  = net before GST, D-C1)
 *   engage_commission_entries      + basis_kind
 */
class CreateCouponTables extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_coupon_ref_seq START 1');
        $db->query("CREATE TABLE IF NOT EXISTS engage_coupons (
            id                          BIGSERIAL PRIMARY KEY,
            coupon_ref                  VARCHAR(16)  NOT NULL UNIQUE,
            code_kind                   VARCHAR(12)  NOT NULL,
            label                       VARCHAR(120) NOT NULL,
            description                 TEXT         NULL,
            discount_type               VARCHAR(8)   NOT NULL,
            percent_bp                  INTEGER      NULL,
            flat_paise                  BIGINT       NULL,
            max_discount_paise          BIGINT       NULL,
            min_order_paise             BIGINT       NULL,
            applies_to                  JSONB        NOT NULL DEFAULT '{\"product_codes\":[],\"offer_codes\":[],\"licence_type_codes\":[]}'::jsonb,
            new_or_renewal              VARCHAR(8)   NOT NULL DEFAULT 'any',
            valid_from                  TIMESTAMPTZ  NULL,
            valid_to                    TIMESTAMPTZ  NULL,
            max_redemptions_total       INTEGER      NULL,
            max_redemptions_per_holder  INTEGER      NULL,
            stackable                   BOOLEAN      NOT NULL DEFAULT FALSE,
            seller_id                   BIGINT       NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT,
            campaign_ref                VARCHAR(64)  NULL,
            status                      VARCHAR(10)  NOT NULL DEFAULT 'draft',
            created_by                  BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            approved_by                 BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            approved_at                 TIMESTAMPTZ  NULL,
            activated_at                TIMESTAMPTZ  NULL,
            paused_at                   TIMESTAMPTZ  NULL,
            ended_at                    TIMESTAMPTZ  NULL,
            retired_at                  TIMESTAMPTZ  NULL,
            retired_by                  BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            version                     INTEGER      NOT NULL DEFAULT 1,
            created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_coupons_kind_chk     CHECK (code_kind IN ('shared','single_use')),
            CONSTRAINT engage_coupons_type_chk     CHECK (
                (discount_type = 'percent' AND percent_bp IS NOT NULL AND flat_paise IS NULL)
                OR (discount_type = 'flat' AND flat_paise IS NOT NULL AND percent_bp IS NULL)),
            CONSTRAINT engage_coupons_bp_chk       CHECK (percent_bp IS NULL OR (percent_bp >= 1 AND percent_bp <= 10000)),
            CONSTRAINT engage_coupons_flat_chk     CHECK (flat_paise IS NULL OR flat_paise >= 1),
            CONSTRAINT engage_coupons_max_chk      CHECK (max_discount_paise IS NULL OR max_discount_paise >= 1),
            CONSTRAINT engage_coupons_min_chk      CHECK (min_order_paise IS NULL OR min_order_paise >= 0),
            CONSTRAINT engage_coupons_applies_chk  CHECK (jsonb_typeof(applies_to) = 'object'),
            CONSTRAINT engage_coupons_nr_chk       CHECK (new_or_renewal IN ('any','new','renewal')),
            CONSTRAINT engage_coupons_valid_chk    CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to > valid_from),
            CONSTRAINT engage_coupons_total_chk    CHECK (max_redemptions_total IS NULL OR max_redemptions_total >= 1),
            CONSTRAINT engage_coupons_holder_chk   CHECK (max_redemptions_per_holder IS NULL OR max_redemptions_per_holder >= 1),
            CONSTRAINT engage_coupons_stack_chk    CHECK (stackable = FALSE),
            CONSTRAINT engage_coupons_status_chk   CHECK (status IN ('draft','active','paused','expired','exhausted','retired')),
            CONSTRAINT engage_coupons_approved_chk CHECK (status IN ('draft','retired') OR approved_at IS NOT NULL),
            CONSTRAINT engage_coupons_version_chk  CHECK (version >= 1)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupons_status ON engage_coupons (status, valid_to)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupons_seller ON engage_coupons (seller_id) WHERE seller_id IS NOT NULL');

        // Rules are immutable once the coupon leaves draft; only the lifecycle columns may change, along allowed moves.
        $db->query("CREATE OR REPLACE FUNCTION engage_coupons_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        DECLARE
            lifecycle text[] := ARRAY['status','approved_by','approved_at','activated_at','paused_at','ended_at','retired_at',
                                      'retired_by','version','updated_at'];
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'engage_coupons: % is % and cannot be deleted (retire it)', OLD.coupon_ref, OLD.status;
                END IF;
                RETURN OLD;
            END IF;
            IF OLD.status <> 'draft' AND (to_jsonb(NEW) - lifecycle) IS DISTINCT FROM (to_jsonb(OLD) - lifecycle) THEN
                RAISE EXCEPTION 'engage_coupons: % is % and its rules are immutable (create a new coupon)', OLD.coupon_ref, OLD.status;
            END IF;
            IF NEW.status <> OLD.status AND NOT (
                   (OLD.status = 'draft'     AND NEW.status IN ('active','retired'))
                OR (OLD.status = 'active'    AND NEW.status IN ('paused','expired','exhausted','retired'))
                OR (OLD.status = 'paused'    AND NEW.status IN ('active','expired','exhausted','retired'))
                OR (OLD.status IN ('expired','exhausted') AND NEW.status = 'retired')) THEN
                RAISE EXCEPTION 'engage_coupons: % cannot move from % to %', OLD.coupon_ref, OLD.status, NEW.status;
            END IF;
            RETURN NEW;
        END
        \$fn\$");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_coupons_guard ON engage_coupons');
        $db->query('CREATE TRIGGER trg_engage_coupons_guard BEFORE UPDATE OR DELETE ON engage_coupons FOR EACH ROW EXECUTE FUNCTION engage_coupons_guard()');

        $db->query("CREATE TABLE IF NOT EXISTS engage_coupon_codes (
            id            BIGSERIAL PRIMARY KEY,
            coupon_id     BIGINT       NOT NULL REFERENCES engage_coupons(id) ON DELETE CASCADE,
            code          VARCHAR(32)  NOT NULL,
            kind          VARCHAR(12)  NOT NULL,
            status        VARCHAR(10)  NOT NULL DEFAULT 'available',
            batch_ref     VARCHAR(32)  NULL,
            created_by    BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            exported_at   TIMESTAMPTZ  NULL,
            export_count  INTEGER      NOT NULL DEFAULT 0,
            updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_coupon_codes_code_uq     UNIQUE (code),
            CONSTRAINT engage_coupon_codes_code_chk    CHECK (code ~ '^[A-Z0-9]{4,32}$'),
            CONSTRAINT engage_coupon_codes_kind_chk    CHECK (kind IN ('shared','single_use')),
            CONSTRAINT engage_coupon_codes_status_chk  CHECK (status IN ('available','reserved','redeemed','void'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupon_codes_coupon ON engage_coupon_codes (coupon_id, status)');

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_quote_ref_seq START 1');
        $db->query("CREATE TABLE IF NOT EXISTS engage_quotes (
            id               BIGSERIAL PRIMARY KEY,
            quote_ref        VARCHAR(24)  NOT NULL UNIQUE,
            source           VARCHAR(16)  NOT NULL DEFAULT 'staff',
            holder_uuid      VARCHAR(64)  NULL,
            cmp_id           BIGINT       NULL,
            lead_id          BIGINT       NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            kind             VARCHAR(8)   NOT NULL DEFAULT 'new',
            offer_id         BIGINT       NOT NULL REFERENCES engage_offers(id) ON DELETE RESTRICT,
            offer_code       VARCHAR(64)  NOT NULL,
            offer_version    INTEGER      NOT NULL,
            product_code     VARCHAR(64)  NOT NULL,
            price_basis      VARCHAR(10)  NOT NULL,
            gst_rate         NUMERIC(5,2) NOT NULL,
            list_paise       BIGINT       NOT NULL,
            discount_paise   BIGINT       NOT NULL DEFAULT 0,
            net_paise        BIGINT       NOT NULL,
            taxable_paise    BIGINT       NOT NULL,
            gst_paise        BIGINT       NOT NULL,
            total_paise      BIGINT       NOT NULL,
            coupon_id        BIGINT       NULL REFERENCES engage_coupons(id) ON DELETE RESTRICT,
            coupon_code      VARCHAR(32)  NULL,
            status           VARCHAR(10)  NOT NULL DEFAULT 'open',
            expires_at       TIMESTAMPTZ  NOT NULL,
            licence_ref      VARCHAR(32)  NULL,
            used_at          TIMESTAMPTZ  NULL,
            idempotency_key  VARCHAR(120) NULL UNIQUE,
            created_by       BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_quotes_who_chk     CHECK (holder_uuid IS NOT NULL OR cmp_id IS NOT NULL),
            CONSTRAINT engage_quotes_source_chk  CHECK (source IN ('staff','manage')),
            CONSTRAINT engage_quotes_kind_chk    CHECK (kind IN ('new','renewal')),
            CONSTRAINT engage_quotes_basis_chk   CHECK (price_basis IN ('inclusive','exclusive')),
            CONSTRAINT engage_quotes_money_chk   CHECK (list_paise >= 0 AND discount_paise >= 0 AND net_paise = list_paise - discount_paise
                                                       AND taxable_paise + gst_paise = CASE WHEN price_basis = 'inclusive' THEN net_paise ELSE total_paise END
                                                       AND total_paise >= net_paise),
            CONSTRAINT engage_quotes_disc_chk    CHECK (discount_paise = 0 OR (coupon_id IS NOT NULL AND net_paise >= 100)),
            CONSTRAINT engage_quotes_status_chk  CHECK (status IN ('open','used','expired','cancelled'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_quotes_created ON engage_quotes (created_at DESC, id DESC)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_coupon_redemptions (
            id                    BIGSERIAL PRIMARY KEY,
            coupon_id             BIGINT       NOT NULL REFERENCES engage_coupons(id) ON DELETE RESTRICT,
            code_id               BIGINT       NOT NULL REFERENCES engage_coupon_codes(id) ON DELETE RESTRICT,
            code                  VARCHAR(32)  NOT NULL,
            single_use            BOOLEAN      NOT NULL DEFAULT FALSE,
            holder_uuid           VARCHAR(64)  NULL,
            cmp_id                BIGINT       NULL,
            holder_key            VARCHAR(80)  NOT NULL,
            lead_id               BIGINT       NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            seller_id             BIGINT       NULL REFERENCES engage_sellers(id) ON DELETE SET NULL,
            kind                  VARCHAR(8)   NOT NULL DEFAULT 'new',
            offer_code            VARCHAR(64)  NOT NULL,
            offer_version         INTEGER      NOT NULL,
            product_code          VARCHAR(64)  NOT NULL,
            price_basis           VARCHAR(10)  NOT NULL,
            gst_rate              NUMERIC(5,2) NOT NULL,
            quote_ref             VARCHAR(24)  NULL,
            list_paise            BIGINT       NOT NULL,
            discount_paise        BIGINT       NOT NULL,
            net_paise             BIGINT       NOT NULL,
            taxable_paise         BIGINT       NOT NULL,
            gst_paise             BIGINT       NOT NULL,
            total_paise           BIGINT       NOT NULL,
            state                 VARCHAR(10)  NOT NULL DEFAULT 'reserved',
            reserved_until        TIMESTAMPTZ  NOT NULL,
            committed_at          TIMESTAMPTZ  NULL,
            released_at           TIMESTAMPTZ  NULL,
            release_reason        VARCHAR(255) NULL,
            reversed_at           TIMESTAMPTZ  NULL,
            reversal_reason       TEXT         NULL,
            licence_ref           VARCHAR(32)  NULL,
            finance_document_ref  VARCHAR(96)  NULL,
            idempotency_key       VARCHAR(120) NOT NULL,
            source                VARCHAR(16)  NOT NULL DEFAULT 'staff',
            created_by            BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            actor_by              BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_coupon_redemptions_idem_uq    UNIQUE (idempotency_key),
            CONSTRAINT engage_coupon_redemptions_state_chk  CHECK (state IN ('reserved','committed','released','reversed')),
            CONSTRAINT engage_coupon_redemptions_who_chk    CHECK (holder_uuid IS NOT NULL OR cmp_id IS NOT NULL),
            CONSTRAINT engage_coupon_redemptions_kind_chk   CHECK (kind IN ('new','renewal')),
            CONSTRAINT engage_coupon_redemptions_basis_chk  CHECK (price_basis IN ('inclusive','exclusive')),
            CONSTRAINT engage_coupon_redemptions_money_chk  CHECK (discount_paise >= 1 AND net_paise = list_paise - discount_paise AND net_paise >= 100
                                                                   AND taxable_paise >= 0 AND gst_paise >= 0 AND total_paise >= net_paise),
            CONSTRAINT engage_coupon_redemptions_commit_chk CHECK (state NOT IN ('committed','reversed') OR (committed_at IS NOT NULL AND licence_ref IS NOT NULL)),
            CONSTRAINT engage_coupon_redemptions_rel_chk    CHECK (state <> 'released' OR released_at IS NOT NULL),
            CONSTRAINT engage_coupon_redemptions_rev_chk    CHECK (state <> 'reversed' OR (reversed_at IS NOT NULL AND reversal_reason IS NOT NULL)),
            CONSTRAINT engage_coupon_redemptions_src_chk    CHECK (source IN ('staff','manage'))
        )");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_coupon_redemptions_live ON engage_coupon_redemptions (coupon_id, holder_key) WHERE state IN ('reserved','committed')");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_coupon_redemptions_due ON engage_coupon_redemptions (reserved_until) WHERE state = 'reserved'");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupon_redemptions_quote ON engage_coupon_redemptions (quote_ref)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupon_redemptions_seller ON engage_coupon_redemptions (seller_id, state)');
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_coupon_redemptions_single_use ON engage_coupon_redemptions (code_id) WHERE single_use AND state IN ('reserved','committed')");

        // reserved → committed | released, committed → reversed; amounts and identity never change; never deleted.
        $db->query("CREATE OR REPLACE FUNCTION engage_coupon_redemptions_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        DECLARE
            mutable text[] := ARRAY['state','committed_at','released_at','release_reason','reversed_at','reversal_reason',
                                    'licence_ref','finance_document_ref','lead_id','seller_id','actor_by','updated_at'];
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'engage_coupon_redemptions is a ledger: rows are never deleted';
            END IF;
            IF (to_jsonb(NEW) - mutable) IS DISTINCT FROM (to_jsonb(OLD) - mutable) THEN
                RAISE EXCEPTION 'engage_coupon_redemptions: the amounts and identity of a redemption are immutable';
            END IF;
            IF OLD.state IN ('committed','reversed') AND NEW.licence_ref IS DISTINCT FROM OLD.licence_ref THEN
                RAISE EXCEPTION 'engage_coupon_redemptions: a committed redemption keeps its licence';
            END IF;
            IF NEW.state <> OLD.state AND NOT (
                   (OLD.state = 'reserved'  AND NEW.state IN ('committed','released'))
                OR (OLD.state = 'committed' AND NEW.state = 'reversed')) THEN
                RAISE EXCEPTION 'engage_coupon_redemptions: % cannot move from % to %', OLD.id, OLD.state, NEW.state;
            END IF;
            IF NEW.state = OLD.state AND OLD.state IN ('released','reversed') AND to_jsonb(NEW) - 'updated_at' IS DISTINCT FROM to_jsonb(OLD) - 'updated_at' THEN
                RAISE EXCEPTION 'engage_coupon_redemptions: a % redemption is final', OLD.state;
            END IF;
            RETURN NEW;
        END
        \$fn\$");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_coupon_redemptions_guard ON engage_coupon_redemptions');
        $db->query('CREATE TRIGGER trg_engage_coupon_redemptions_guard BEFORE UPDATE OR DELETE ON engage_coupon_redemptions FOR EACH ROW EXECUTE FUNCTION engage_coupon_redemptions_guard()');

        $db->query("CREATE TABLE IF NOT EXISTS engage_coupon_attempts (
            id           BIGSERIAL PRIMARY KEY,
            channel      VARCHAR(24)  NOT NULL,
            scope_key    VARCHAR(96)  NOT NULL,
            code_sha256  CHAR(64)     NULL,
            coupon_id    BIGINT       NULL REFERENCES engage_coupons(id) ON DELETE SET NULL,
            outcome      VARCHAR(24)  NOT NULL,
            reason       VARCHAR(48)  NULL,
            ip_address   VARCHAR(64)  NULL,
            created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_coupon_attempts_channel_chk CHECK (channel IN ('manage_preview','manage_upgrade','staff_quote'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupon_attempts_scope ON engage_coupon_attempts (channel, scope_key, created_at)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_coupon_attempts_created ON engage_coupon_attempts (created_at)');

        // Manage "Request upgrade" → a subscription inquiry staff follow up (existing table, additive columns).
        foreach ([
            'request_ref VARCHAR(24) NULL', 'request_key VARCHAR(120) NULL', 'source VARCHAR(24) NULL', 'holder_uuid VARCHAR(64) NULL',
            'cmp_id BIGINT NULL', 'offer_code VARCHAR(64) NULL', 'quote_ref VARCHAR(24) NULL', 'contact_name VARCHAR(191) NULL',
            'contact_email VARCHAR(191) NULL', 'contact_phone VARCHAR(32) NULL',
        ] as $col) {
            $db->query('ALTER TABLE engage_subscription_inquiries ADD COLUMN IF NOT EXISTS ' . $col);
        }
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_subscription_inquiries_request_ref ON engage_subscription_inquiries (request_ref) WHERE request_ref IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_subscription_inquiries_request_key ON engage_subscription_inquiries (request_key) WHERE request_key IS NOT NULL');
        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_upgrade_request_ref_seq START 1');

        // Seller commission on the net invoiced (after discount, before GST) — D-C1.
        foreach (['quote_ref VARCHAR(24) NULL', 'list_paise BIGINT NULL', 'discount_paise BIGINT NULL', 'basis_paise BIGINT NULL', 'basis_kind VARCHAR(24) NULL'] as $col) {
            $db->query('ALTER TABLE engage_lead_payments ADD COLUMN IF NOT EXISTS ' . $col);
        }
        $db->query("DO \$\$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'engage_lead_payments_basis_chk') THEN
                ALTER TABLE engage_lead_payments ADD CONSTRAINT engage_lead_payments_basis_chk
                    CHECK ((basis_paise IS NULL AND basis_kind IS NULL) OR (basis_paise > 0 AND basis_kind IN ('net_before_gst','payment_amount')));
            END IF;
        END \$\$");
        $db->query('ALTER TABLE engage_commission_entries ADD COLUMN IF NOT EXISTS basis_kind VARCHAR(24) NULL');
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query('ALTER TABLE engage_commission_entries DROP COLUMN IF EXISTS basis_kind');
        $db->query('ALTER TABLE engage_lead_payments DROP CONSTRAINT IF EXISTS engage_lead_payments_basis_chk');
        foreach (['quote_ref', 'list_paise', 'discount_paise', 'basis_paise', 'basis_kind'] as $c) {
            $db->query('ALTER TABLE engage_lead_payments DROP COLUMN IF EXISTS ' . $c);
        }
        $db->query('DROP INDEX IF EXISTS uq_engage_subscription_inquiries_request_ref');
        $db->query('DROP INDEX IF EXISTS uq_engage_subscription_inquiries_request_key');
        foreach (['request_ref', 'request_key', 'source', 'holder_uuid', 'cmp_id', 'offer_code', 'quote_ref', 'contact_name', 'contact_email', 'contact_phone'] as $c) {
            $db->query('ALTER TABLE engage_subscription_inquiries DROP COLUMN IF EXISTS ' . $c);
        }
        $db->query('DROP SEQUENCE IF EXISTS engage_upgrade_request_ref_seq');
        $db->query('DROP TABLE IF EXISTS engage_coupon_attempts');
        $db->query('DROP TABLE IF EXISTS engage_coupon_redemptions');
        $db->query('DROP TABLE IF EXISTS engage_quotes');
        $db->query('DROP SEQUENCE IF EXISTS engage_quote_ref_seq');
        $db->query('DROP TABLE IF EXISTS engage_coupon_codes');
        $db->query('DROP TABLE IF EXISTS engage_coupons');
        $db->query('DROP SEQUENCE IF EXISTS engage_coupon_ref_seq');
        $db->query('DROP FUNCTION IF EXISTS engage_coupon_redemptions_guard()');
        $db->query('DROP FUNCTION IF EXISTS engage_coupons_guard()');
    }
}

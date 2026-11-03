<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Giving a customer tokens (docs/TOKEN_CONVERSIONS.md §10).
 *
 * Two ways tokens reach a wallet, and one way they are delivered.
 *
 *   engage_token_vouchers         a VALUE instrument — "this is worth N tokens" — redeemed
 *                                 straight into the wallet. Not a coupon. A coupon is a
 *                                 discount rule applied to a price at the moment of sale
 *                                 (docs/COUPONS.md: "not a prepaid voucher or wallet credit"),
 *                                 which is why it cannot hold a balance, cannot take a price
 *                                 to zero, and has nothing to roll back. A voucher is the
 *                                 opposite: it HAS a balance, it is spent rather than applied,
 *                                 and what is left over is either kept or forfeited —
 *                                 `rollback` says which, as a real column rather than a hope.
 *
 *   engage_token_voucher_redemptions  one row per use, append-only, with the tokens taken and
 *                                 the balance left. A single-use voucher has at most one.
 *
 *   engage_token_grants           the outbox. EVERY grant — voucher, purchase, goodwill —
 *                                 becomes a row here and is pushed to Pulse by
 *                                 `engage:token-grants-push`. Tokens are never handed over
 *                                 inside the request that creates them: a customer's paid
 *                                 balance must not depend on Pulse being reachable at that
 *                                 instant, and a push that fails has to be retryable without
 *                                 risk of granting twice. grant_ref is unique and Pulse is
 *                                 idempotent on it, so a retry credits once.
 *
 * Nothing is seeded: no voucher exists until staff create one.
 */
class CreateTokenGrants extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_token_voucher_ref_seq START 1');
        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_token_grant_ref_seq START 1');

        // ── vouchers ────────────────────────────────────────────────────────
        $db->query("CREATE TABLE IF NOT EXISTS engage_token_vouchers (
            id                  BIGSERIAL PRIMARY KEY,
            voucher_ref         VARCHAR(16)  NOT NULL UNIQUE,
            label               VARCHAR(120) NOT NULL,
            description         TEXT         NULL,
            -- What the voucher is worth. Staff think in rupees, so a value may be entered as
            -- money and converted at the token price of the day it is CREATED — the rate is
            -- frozen onto the row, because a voucher handed out as 'Rs 1,000 of credit' must
            -- stay worth that many tokens even if the price changes before it is redeemed.
            tokens              BIGINT       NOT NULL,
            issued_value_paise  BIGINT       NULL,
            price_paise_per_1000 INTEGER     NULL,
            -- single_use: one holder, one redemption. shared: a code many holders may use,
            -- each up to max_redemptions_per_holder times.
            code_kind           VARCHAR(12)  NOT NULL DEFAULT 'single_use',
            -- keep_balance: a partial redemption leaves the rest for next time.
            -- no_rollback:  whatever is not taken on the first redemption is forfeited.
            rollback            VARCHAR(12)  NOT NULL DEFAULT 'no_rollback',
            min_tokens          BIGINT       NULL,
            valid_from          TIMESTAMPTZ  NULL,
            valid_to            TIMESTAMPTZ  NULL,
            max_redemptions_total      INTEGER NULL,
            max_redemptions_per_holder INTEGER NULL,
            campaign_ref        VARCHAR(64)  NULL,
            status              VARCHAR(10)  NOT NULL DEFAULT 'draft',
            created_by          BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            edited_by           BIGINT[]     NOT NULL DEFAULT '{}',
            approved_by         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            approved_at         TIMESTAMPTZ  NULL,
            retired_by          BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            retired_at          TIMESTAMPTZ  NULL,
            version             INTEGER      NOT NULL DEFAULT 1,
            created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_token_vouchers_tokens_chk   CHECK (tokens > 0),
            CONSTRAINT engage_token_vouchers_kind_chk     CHECK (code_kind IN ('single_use','shared')),
            CONSTRAINT engage_token_vouchers_rollback_chk CHECK (rollback IN ('no_rollback','keep_balance')),
            CONSTRAINT engage_token_vouchers_status_chk   CHECK (status IN ('draft','active','paused','expired','exhausted','retired')),
            CONSTRAINT engage_token_vouchers_window_chk   CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to > valid_from),
            CONSTRAINT engage_token_vouchers_approved_chk CHECK (status IN ('draft','retired') OR approved_at IS NOT NULL),
            CONSTRAINT engage_token_vouchers_min_chk      CHECK (min_tokens IS NULL OR (min_tokens > 0 AND min_tokens <= tokens)),
            -- keep_balance on a shared voucher would mean one shared pot draining across
            -- unrelated customers, first come first served. That is a different product.
            CONSTRAINT engage_token_vouchers_shared_chk   CHECK (rollback = 'no_rollback' OR code_kind = 'single_use')
        )");

        $db->query("CREATE TABLE IF NOT EXISTS engage_token_voucher_codes (
            id          BIGSERIAL PRIMARY KEY,
            voucher_id  BIGINT       NOT NULL REFERENCES engage_token_vouchers(id) ON DELETE CASCADE,
            code        VARCHAR(32)  NOT NULL UNIQUE,
            kind        VARCHAR(12)  NOT NULL,
            status      VARCHAR(10)  NOT NULL DEFAULT 'available',
            -- What is left on THIS code. Set to the voucher's tokens when the code is made;
            -- a keep_balance redemption reduces it, a no_rollback one empties it.
            tokens_left BIGINT       NOT NULL,
            batch_ref   VARCHAR(32)  NULL,
            created_by  BIGINT       NULL,
            created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_token_voucher_codes_fmt_chk    CHECK (code ~ '^[A-Z0-9]{4,32}$'),
            CONSTRAINT engage_token_voucher_codes_kind_chk   CHECK (kind IN ('single_use','shared')),
            CONSTRAINT engage_token_voucher_codes_status_chk CHECK (status IN ('available','redeemed','void')),
            CONSTRAINT engage_token_voucher_codes_left_chk   CHECK (tokens_left >= 0)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_token_voucher_codes_voucher ON engage_token_voucher_codes (voucher_id)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_token_voucher_redemptions (
            id              BIGSERIAL PRIMARY KEY,
            voucher_id      BIGINT       NOT NULL REFERENCES engage_token_vouchers(id) ON DELETE RESTRICT,
            code_id         BIGINT       NOT NULL REFERENCES engage_token_voucher_codes(id) ON DELETE RESTRICT,
            code            VARCHAR(32)  NOT NULL,
            holder_uuid     VARCHAR(64)  NOT NULL,
            cmp_id          BIGINT       NULL,
            tokens_granted  BIGINT       NOT NULL,
            tokens_left     BIGINT       NOT NULL,
            tokens_forfeited BIGINT      NOT NULL DEFAULT 0,
            grant_ref       VARCHAR(96)  NOT NULL UNIQUE,
            source          VARCHAR(16)  NOT NULL DEFAULT 'manage',
            actor_by        BIGINT       NULL,
            created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_token_voucher_redemptions_amount_chk
                CHECK (tokens_granted > 0 AND tokens_left >= 0 AND tokens_forfeited >= 0)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_token_voucher_redemptions_holder
            ON engage_token_voucher_redemptions (holder_uuid, created_at DESC)');
        // A single-use code is redeemed once, whatever races to it.
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_voucher_redemptions_single
            ON engage_token_voucher_redemptions (code_id)
            WHERE code_id IS NOT NULL AND source <> 'void'");

        // ── the grant outbox ────────────────────────────────────────────────
        $db->query("CREATE TABLE IF NOT EXISTS engage_token_grants (
            id            BIGSERIAL PRIMARY KEY,
            grant_ref     VARCHAR(96)  NOT NULL UNIQUE,
            holder_uuid   VARCHAR(64)  NOT NULL,
            tokens        BIGINT       NOT NULL,
            reason        VARCHAR(500) NOT NULL,
            -- voucher | purchase | goodwill — what this is, for the customer's statement
            -- and for a revenue report that must not count a free grant as a sale.
            origin        VARCHAR(16)  NOT NULL,
            origin_ref    VARCHAR(96)  NULL,
            status        VARCHAR(10)  NOT NULL DEFAULT 'pending',
            attempts      INTEGER      NOT NULL DEFAULT 0,
            next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            last_error    TEXT         NULL,
            delivered_at  TIMESTAMPTZ  NULL,
            created_by    BIGINT       NULL,
            created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_token_grants_tokens_chk CHECK (tokens > 0),
            CONSTRAINT engage_token_grants_status_chk CHECK (status IN ('pending','delivered','failed','dead')),
            CONSTRAINT engage_token_grants_origin_chk CHECK (origin IN ('voucher','purchase','goodwill'))
        )");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_token_grants_due
            ON engage_token_grants (next_attempt_at) WHERE status = 'pending'");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_token_grants_holder
            ON engage_token_grants (holder_uuid, created_at DESC)');

        // Same guard as coupons: once a voucher leaves draft its value and rules are frozen.
        // A voucher that has been handed to customers cannot quietly become worth less.
        $db->query("CREATE OR REPLACE FUNCTION engage_token_vouchers_guard() RETURNS TRIGGER AS \$\$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'a voucher that has left draft is never deleted: retire it';
                END IF;
                RETURN OLD;
            END IF;
            IF OLD.status <> 'draft' AND (
                NEW.tokens, NEW.code_kind, NEW.rollback, NEW.min_tokens, NEW.valid_from, NEW.valid_to,
                NEW.max_redemptions_total, NEW.max_redemptions_per_holder
            ) IS DISTINCT FROM (
                OLD.tokens, OLD.code_kind, OLD.rollback, OLD.min_tokens, OLD.valid_from, OLD.valid_to,
                OLD.max_redemptions_total, OLD.max_redemptions_per_holder
            ) THEN
                RAISE EXCEPTION 'an active voucher cannot be re-valued: retire it and issue another';
            END IF;
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_token_vouchers_guard ON engage_token_vouchers');
        $db->query('CREATE TRIGGER trg_engage_token_vouchers_guard
            BEFORE UPDATE OR DELETE ON engage_token_vouchers
            FOR EACH ROW EXECUTE PROCEDURE engage_token_vouchers_guard()');

        // The redemption ledger and the grant outbox are evidence of value given away.
        $db->query("CREATE OR REPLACE FUNCTION engage_token_redemptions_append_only() RETURNS TRIGGER AS \$\$
        BEGIN
            RAISE EXCEPTION 'engage_token_voucher_redemptions is append-only';
        END;
        \$\$ LANGUAGE plpgsql");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_token_redemptions_append_only ON engage_token_voucher_redemptions');
        $db->query('CREATE TRIGGER trg_engage_token_redemptions_append_only
            BEFORE UPDATE OR DELETE ON engage_token_voucher_redemptions
            FOR EACH ROW EXECUTE PROCEDURE engage_token_redemptions_append_only()');
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query('DROP TRIGGER IF EXISTS trg_engage_token_redemptions_append_only ON engage_token_voucher_redemptions');
        $db->query('DROP TRIGGER IF EXISTS trg_engage_token_vouchers_guard ON engage_token_vouchers');
        $db->query('DROP FUNCTION IF EXISTS engage_token_redemptions_append_only()');
        $db->query('DROP FUNCTION IF EXISTS engage_token_vouchers_guard()');
        $db->query('DROP TABLE IF EXISTS engage_token_grants');
        $db->query('DROP TABLE IF EXISTS engage_token_voucher_redemptions');
        $db->query('DROP TABLE IF EXISTS engage_token_voucher_codes');
        $db->query('DROP TABLE IF EXISTS engage_token_vouchers');
        $db->query('DROP SEQUENCE IF EXISTS engage_token_grant_ref_seq');
        $db->query('DROP SEQUENCE IF EXISTS engage_token_voucher_ref_seq');
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Token conversion rates (docs/TOKEN_CONVERSIONS.md) — what a model's tokens cost a customer.
 *
 * AICOUNTLY charges a customer's quota in TOKENS, not in an abstract credit. Providers price
 * models very differently, and we do not want a different price per model, so instead the TOKEN
 * COUNT is weighted: a call on an expensive model consumes more of the customer's allowance than
 * the same call on a cheap one.
 *
 *   billable tokens = round( (input + output) x multiplier )
 *
 * Engage owns the multiplier because it is a commercial rate and sales owns pricing. Console owns
 * which models exist (ai_models) and classifies each one's tier; Pulse owns enforcement and does
 * the arithmetic. Engage pushes the card to both over the AIC-S2S rail that already carries
 * entitlements.
 *
 *   engage_token_rates     one multiplier per (provider, model). model_key NULL means "every model
 *                          of this provider that has no rate of its own". draft → active → retired,
 *                          four eyes to activate, and once out of draft the commercial columns are
 *                          IMMUTABLE (trigger engage_token_rates_guard) — a rate that has priced
 *                          real usage can never be edited, only retired and replaced. At most one
 *                          ACTIVE rate per (provider, model) at a time.
 *
 * Multipliers are held as integer BASIS POINTS of 1x (10000 = 1x, 100000 = 10x, 250000 = 25x), so
 * nothing that decides what a customer is charged is ever a float.
 *
 * Settings (engage_settings), seeded with the founder's decisions so the system is usable the day
 * it ships, and changeable without a deploy:
 *
 *   token_price_paise_per_1000   100   = Rs 1.00 per 1,000 tokens, EXCLUSIVE of GST
 *   token_gst_rate               18
 *   token_sac                    997331
 *   token_tier_fallback_bp       low 1x / medium 10x / high 25x — used when a model has no rate
 *   token_unclassified_tier      medium — a model Console has not classified, which is also warned about
 *
 * Nothing else is seeded: no rate exists until staff create and activate one, so this migration
 * changes nothing for existing Engage users. Until a rate is activated every model falls back to
 * its Console tier, and every active model without a rate is listed as a warning in Engage and
 * Console rather than being quietly priced.
 */
class CreateTokenRates extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_token_rate_ref_seq START 1');
        $db->query("CREATE TABLE IF NOT EXISTS engage_token_rates (
            id              BIGSERIAL PRIMARY KEY,
            rate_ref        VARCHAR(16)  NOT NULL UNIQUE,
            provider_code   VARCHAR(48)  NOT NULL,
            model_key       VARCHAR(96)  NULL,
            multiplier_bp   INTEGER      NOT NULL,
            label           VARCHAR(120) NOT NULL,
            notes           TEXT         NULL,
            effective_from  TIMESTAMPTZ  NULL,
            effective_to    TIMESTAMPTZ  NULL,
            status          VARCHAR(10)  NOT NULL DEFAULT 'draft',
            created_by      BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            edited_by       BIGINT[]     NOT NULL DEFAULT '{}',
            approved_by     BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            approved_at     TIMESTAMPTZ  NULL,
            retired_by      BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            retired_at      TIMESTAMPTZ  NULL,
            version         INTEGER      NOT NULL DEFAULT 1,
            created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_token_rates_status_chk CHECK (status IN ('draft','active','retired')),
            -- 0 is allowed and means 'free on this model'; a negative multiplier would pay the
            -- customer for using AI. The ceiling is 1000x, which is far beyond any real price
            -- spread and catches a basis-points/multiplier mix-up before it prices anything.
            CONSTRAINT engage_token_rates_mult_chk CHECK (multiplier_bp >= 0 AND multiplier_bp <= 10000000),
            CONSTRAINT engage_token_rates_window_chk CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from),
            CONSTRAINT engage_token_rates_approved_chk CHECK (status = 'draft' OR approved_at IS NOT NULL)
        )");

        // One live rate per target. COALESCE gives the provider-wide row ('*') a slot of its own,
        // so a provider default and a model override never collide.
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_rates_active
            ON engage_token_rates (provider_code, COALESCE(model_key, '*')) WHERE status = 'active'");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_token_rates_status ON engage_token_rates (status, provider_code)');

        // Same shape as engage_coupons_guard: a rate that has priced real usage is evidence, so
        // once it leaves draft only its lifecycle columns may move, and the status graph is
        // enforced here rather than trusted to the service layer.
        $db->query("CREATE OR REPLACE FUNCTION engage_token_rates_guard() RETURNS TRIGGER AS \$\$
        DECLARE
            lifecycle TEXT[] := ARRAY['status','approved_by','approved_at','retired_by','retired_at','effective_to','version','updated_at'];
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'a token rate that has left draft is never deleted: retire it';
                END IF;
                RETURN OLD;
            END IF;

            IF OLD.status <> 'draft' THEN
                IF (NEW.provider_code, NEW.model_key, NEW.multiplier_bp, NEW.label, NEW.effective_from)
                   IS DISTINCT FROM
                   (OLD.provider_code, OLD.model_key, OLD.multiplier_bp, OLD.label, OLD.effective_from) THEN
                    RAISE EXCEPTION 'an active or retired token rate cannot be edited: retire it and add a new one';
                END IF;
            END IF;

            IF NEW.status <> OLD.status THEN
                IF NOT (
                    (OLD.status = 'draft'  AND NEW.status IN ('active','retired')) OR
                    (OLD.status = 'active' AND NEW.status = 'retired')
                ) THEN
                    RAISE EXCEPTION 'token rate status cannot move from % to %', OLD.status, NEW.status;
                END IF;
            END IF;

            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_token_rates_guard ON engage_token_rates');
        $db->query('CREATE TRIGGER trg_engage_token_rates_guard
            BEFORE UPDATE OR DELETE ON engage_token_rates
            FOR EACH ROW EXECUTE PROCEDURE engage_token_rates_guard()');

        $settings = [
            ['token_price_paise_per_1000', '100',
                'What 1,000 tokens cost a customer, in paise, EXCLUSIVE of GST. 100 = Rs 1.00 per 1,000 tokens, so Rs 1,000 of credit = 1,000,000 tokens + 18% GST = Rs 1,180 collected.'],
            ['token_gst_rate', '18',
                'GST rate on a token purchase, per cent. Applied to the price after any discount (CGST s.15(3)(a)).'],
            ['token_sac', '"997331"',
                'SAC printed on a token purchase invoice. Finance accepts 99 followed by four digits.'],
            ['token_tier_fallback_bp', '{"low":10000,"medium":100000,"high":250000}',
                'Multiplier in basis points of 1x used when a model has no rate of its own, by the tier Console gives it: low 1x, medium 10x, high 25x.'],
            ['token_unclassified_tier', '"medium"',
                'Tier used for a model Console has not classified. Such a model is also listed as a warning in Engage and Console — it is charged, but never silently.'],
        ];
        foreach ($settings as [$key, $json, $description]) {
            $db->query(
                'INSERT INTO engage_settings (key, value_json, description, created_at, updated_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT (key) DO NOTHING',
                [$key, $json, $description]
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS trg_engage_token_rates_guard ON engage_token_rates');
        $this->db->query('DROP FUNCTION IF EXISTS engage_token_rates_guard()');
        $this->db->query('DROP TABLE IF EXISTS engage_token_rates');
        $this->db->query('DROP SEQUENCE IF EXISTS engage_token_rate_ref_seq');
        $this->db->query("DELETE FROM engage_settings WHERE key IN
            ('token_price_paise_per_1000','token_gst_rate','token_sac','token_tier_fallback_bp','token_unclassified_tier')");
    }
}

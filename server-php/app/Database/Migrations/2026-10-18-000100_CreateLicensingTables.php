<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Licensing, Phase A (docs/LICENSING.md). Nothing here is seeded: there are no licence types, offers or prices
 * until staff create them, so the migration changes nothing for existing Engage users.
 *
 *   engage_licence_types        lookup of licence types (data, not code). lifecycle_kind paid | trial | complimentary
 *                               is the only fixed vocabulary — it is what Manage's ghm_subscription.kind understands.
 *   engage_offers               the sellable catalogue: versioned per offer_code, draft → published → retired.
 *                               A published (or retired) version is immutable — trigger engage_offers_guard.
 *                               At most one published version per offer_code.
 *   engage_account_companies    an Engage account ↔ a Manage company (cmp_id), mapped and verified by staff.
 *                               A cmp_id belongs to at most one account at a time.
 *   engage_licences             issued licences, one company each, with a snapshot of the offer version they came from.
 *                               issued → active ⇄ suspended, active → expired, any non-final → revoked. version = optimistic lock.
 *                               order_ref (UNIQUE, nullable) is reserved for Phase B (Finance licence orders).
 *   engage_licence_events       append-only (trigger) activity per company; seq is assigned per cmp_id by a trigger.
 *   engage_licence_event_counters  per-cmp_id seq counter used by that trigger.
 *   engage_licence_seats        seat assignments (member identifier optional).
 *   engage_s2s_nonces           AIC-S2S v1 nonce replay window (600 s per key id).
 *   engage_s2s_rate             AIC-S2S v1 per-key, per-minute request counters (contract §0.4).
 */
class CreateLicensingTables extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query("CREATE TABLE IF NOT EXISTS engage_licence_types (
            id              BIGSERIAL PRIMARY KEY,
            code            VARCHAR(48)  NOT NULL UNIQUE,
            label           VARCHAR(120) NOT NULL,
            description     TEXT         NULL,
            lifecycle_kind  VARCHAR(16)  NOT NULL,
            display_schema  JSONB        NOT NULL DEFAULT '{}'::jsonb,
            active          BOOLEAN      NOT NULL DEFAULT TRUE,
            sort_order      INTEGER      NOT NULL DEFAULT 0,
            created_by      BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_licence_types_code_chk CHECK (code ~ '^[a-z0-9][a-z0-9_\\-]{1,47}$'),
            CONSTRAINT engage_licence_types_kind_chk CHECK (lifecycle_kind IN ('paid','trial','complimentary'))
        )");

        $db->query("CREATE TABLE IF NOT EXISTS engage_offers (
            id                 BIGSERIAL PRIMARY KEY,
            offer_code         VARCHAR(64)  NOT NULL,
            version            INTEGER      NOT NULL DEFAULT 1,
            product_code       VARCHAR(64)  NOT NULL REFERENCES engage_products(code) ON UPDATE CASCADE ON DELETE RESTRICT,
            licence_type_code  VARCHAR(48)  NOT NULL REFERENCES engage_licence_types(code) ON UPDATE CASCADE ON DELETE RESTRICT,
            plan_code          VARCHAR(64)  NULL REFERENCES engage_plans(code) ON UPDATE CASCADE ON DELETE RESTRICT,
            label              VARCHAR(191) NOT NULL,
            description        TEXT         NULL,
            price_paise        BIGINT       NOT NULL,
            currency           VARCHAR(3)   NOT NULL DEFAULT 'INR',
            price_basis        VARCHAR(10)  NOT NULL,
            gst_rate           NUMERIC(5,2) NOT NULL,
            sac                VARCHAR(8)   NULL,
            term_months        INTEGER      NOT NULL DEFAULT 0,
            term_days          INTEGER      NOT NULL DEFAULT 0,
            seats              INTEGER      NULL,
            app_keys           TEXT[]       NOT NULL DEFAULT '{}',
            entitlements       JSONB        NOT NULL DEFAULT '[]'::jsonb,
            display            JSONB        NOT NULL DEFAULT '{}'::jsonb,
            status             VARCHAR(12)  NOT NULL DEFAULT 'draft',
            valid_from         TIMESTAMPTZ  NULL,
            valid_to           TIMESTAMPTZ  NULL,
            published_at       TIMESTAMPTZ  NULL,
            published_by       BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            retired_at         TIMESTAMPTZ  NULL,
            retired_by         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_by         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            UNIQUE (offer_code, version),
            CONSTRAINT engage_offers_code_chk   CHECK (offer_code ~ '^[a-z0-9][a-z0-9_\\-]{1,63}$'),
            CONSTRAINT engage_offers_status_chk CHECK (status IN ('draft','published','retired')),
            CONSTRAINT engage_offers_basis_chk  CHECK (price_basis IN ('inclusive','exclusive')),
            CONSTRAINT engage_offers_price_chk  CHECK (price_paise >= 0),
            CONSTRAINT engage_offers_gst_chk    CHECK (gst_rate >= 0 AND gst_rate <= 100),
            CONSTRAINT engage_offers_sac_chk    CHECK (sac IS NULL OR sac ~ '^[0-9]{4,8}$'),
            CONSTRAINT engage_offers_term_chk   CHECK (term_months >= 0 AND term_days >= 0 AND term_months <= 120 AND term_days <= 3660),
            CONSTRAINT engage_offers_seats_chk  CHECK (seats IS NULL OR seats >= 1),
            CONSTRAINT engage_offers_valid_chk  CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to > valid_from),
            CONSTRAINT engage_offers_ent_chk    CHECK (jsonb_typeof(entitlements) = 'array'),
            CONSTRAINT engage_offers_display_chk CHECK (jsonb_typeof(display) = 'object')
        )");
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_offers_published ON engage_offers (offer_code) WHERE status = 'published'");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_offers_product ON engage_offers (product_code, status)');

        // Published / retired versions are immutable: only published → retired (+ retired_at/by, updated_at) may change,
        // and only drafts may be deleted. Licences keep their own snapshot as well.
        $db->query("CREATE OR REPLACE FUNCTION engage_offers_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'engage_offers: % v% is % and cannot be deleted', OLD.offer_code, OLD.version, OLD.status;
                END IF;
                RETURN OLD;
            END IF;
            IF OLD.status <> 'draft' THEN
                IF (NEW.offer_code, NEW.version, NEW.product_code, NEW.licence_type_code, NEW.plan_code, NEW.label,
                    NEW.description, NEW.price_paise, NEW.currency, NEW.price_basis, NEW.gst_rate, NEW.sac, NEW.term_months,
                    NEW.term_days, NEW.seats, NEW.app_keys, NEW.entitlements, NEW.display, NEW.valid_from, NEW.valid_to,
                    NEW.published_at, NEW.published_by, NEW.created_by, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.offer_code, OLD.version, OLD.product_code, OLD.licence_type_code, OLD.plan_code, OLD.label,
                    OLD.description, OLD.price_paise, OLD.currency, OLD.price_basis, OLD.gst_rate, OLD.sac, OLD.term_months,
                    OLD.term_days, OLD.seats, OLD.app_keys, OLD.entitlements, OLD.display, OLD.valid_from, OLD.valid_to,
                    OLD.published_at, OLD.published_by, OLD.created_by, OLD.created_at) THEN
                    RAISE EXCEPTION 'engage_offers: % v% is % and immutable — create a new version', OLD.offer_code, OLD.version, OLD.status;
                END IF;
                IF NEW.status <> OLD.status AND NOT (OLD.status = 'published' AND NEW.status = 'retired') THEN
                    RAISE EXCEPTION 'engage_offers: % v% cannot move from % to %', OLD.offer_code, OLD.version, OLD.status, NEW.status;
                END IF;
            END IF;
            RETURN NEW;
        END
        \$fn\$");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_offers_guard ON engage_offers');
        $db->query('CREATE TRIGGER trg_engage_offers_guard BEFORE UPDATE OR DELETE ON engage_offers FOR EACH ROW EXECUTE FUNCTION engage_offers_guard()');

        $db->query("CREATE TABLE IF NOT EXISTS engage_account_companies (
            id                   BIGSERIAL PRIMARY KEY,
            account_id           BIGINT       NOT NULL REFERENCES engage_accounts(id) ON DELETE RESTRICT,
            cmp_id               BIGINT       NOT NULL,
            company_name         VARCHAR(255) NULL,
            status               VARCHAR(12)  NOT NULL DEFAULT 'active',
            verification_method  VARCHAR(24)  NOT NULL DEFAULT 'manual',
            verified_by          BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            verified_by_display  VARCHAR(191) NOT NULL,
            verified_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            verification_note    TEXT         NULL,
            unlinked_at          TIMESTAMPTZ  NULL,
            unlinked_by          BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            unlink_reason        TEXT         NULL,
            created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_account_companies_cmp_chk    CHECK (cmp_id > 0),
            CONSTRAINT engage_account_companies_status_chk CHECK (status IN ('active','unlinked')),
            CONSTRAINT engage_account_companies_method_chk CHECK (verification_method IN ('manual'))
        )");
        // A Manage company belongs to at most one Engage account at a time (an account may hold several companies).
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_account_companies_cmp ON engage_account_companies (cmp_id) WHERE status = 'active'");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_account_companies_account ON engage_account_companies (account_id, status)');

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_licence_ref_seq START 1');
        $db->query("CREATE TABLE IF NOT EXISTS engage_licences (
            id                 BIGSERIAL PRIMARY KEY,
            licence_ref        VARCHAR(32)  NOT NULL UNIQUE,
            cmp_id             BIGINT       NOT NULL,
            account_id         BIGINT       NOT NULL REFERENCES engage_accounts(id) ON DELETE RESTRICT,
            offer_id           BIGINT       NOT NULL REFERENCES engage_offers(id) ON DELETE RESTRICT,
            offer_code         VARCHAR(64)  NOT NULL,
            offer_version      INTEGER      NOT NULL,
            offer_snapshot     JSONB        NOT NULL,
            product_code       VARCHAR(64)  NOT NULL,
            licence_type_code  VARCHAR(48)  NOT NULL,
            plan_code          VARCHAR(64)  NULL,
            app_keys           TEXT[]       NOT NULL DEFAULT '{}',
            seats_total        INTEGER      NULL,
            starts_at          TIMESTAMPTZ  NOT NULL,
            ends_at            TIMESTAMPTZ  NULL,
            status             VARCHAR(12)  NOT NULL DEFAULT 'issued',
            status_reason      TEXT         NULL,
            order_ref          VARCHAR(96)  NULL UNIQUE,
            invoice_refs       TEXT[]       NOT NULL DEFAULT '{}',
            source             VARCHAR(8)   NOT NULL DEFAULT 'staff',
            notes              TEXT         NULL,
            version            INTEGER      NOT NULL DEFAULT 1,
            activated_at       TIMESTAMPTZ  NULL,
            suspended_at       TIMESTAMPTZ  NULL,
            revoked_at         TIMESTAMPTZ  NULL,
            expired_at         TIMESTAMPTZ  NULL,
            created_by         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_licences_cmp_chk    CHECK (cmp_id > 0),
            CONSTRAINT engage_licences_status_chk CHECK (status IN ('issued','active','suspended','expired','revoked')),
            CONSTRAINT engage_licences_source_chk CHECK (source IN ('staff','order')),
            CONSTRAINT engage_licences_order_chk  CHECK (source <> 'order' OR order_ref IS NOT NULL),
            CONSTRAINT engage_licences_term_chk   CHECK (ends_at IS NULL OR ends_at > starts_at),
            CONSTRAINT engage_licences_seats_chk  CHECK (seats_total IS NULL OR seats_total >= 0),
            CONSTRAINT engage_licences_version_chk CHECK (version >= 1)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licences_cmp ON engage_licences (cmp_id, created_at DESC, id DESC)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licences_account ON engage_licences (account_id)');
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_licences_due ON engage_licences (ends_at) WHERE status IN ('issued','active')");

        $db->query("CREATE TABLE IF NOT EXISTS engage_licence_event_counters (
            cmp_id   BIGINT PRIMARY KEY,
            last_seq BIGINT NOT NULL
        )");
        $db->query("CREATE TABLE IF NOT EXISTS engage_licence_events (
            id               BIGSERIAL PRIMARY KEY,
            event_id         VARCHAR(40)  NOT NULL UNIQUE,
            licence_id       BIGINT       NOT NULL REFERENCES engage_licences(id) ON DELETE RESTRICT,
            licence_ref      VARCHAR(32)  NOT NULL,
            cmp_id           BIGINT       NOT NULL,
            seq              BIGINT       NOT NULL,
            type             VARCHAR(24)  NOT NULL,
            actor_kind       VARCHAR(12)  NOT NULL,
            actor_id         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            actor_display    VARCHAR(191) NOT NULL,
            summary          TEXT         NOT NULL,
            reason           TEXT         NULL,
            changes          JSONB        NOT NULL DEFAULT '{}'::jsonb,
            licence_version  INTEGER      NOT NULL,
            occurred_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            UNIQUE (cmp_id, seq),
            CONSTRAINT engage_licence_events_type_chk CHECK (type IN ('issued','activated','renewed','extended','upgraded',
                'suspended','reinstated','expired','revoked','seats_changed','seat_assigned','seat_released','invoice_linked')),
            CONSTRAINT engage_licence_events_actor_chk CHECK (actor_kind IN ('staff','system','order'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licence_events_cmp ON engage_licence_events (cmp_id, occurred_at DESC, id DESC)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licence_events_licence ON engage_licence_events (licence_id, id)');

        // seq per cmp_id, assigned in the inserting transaction (the counter row lock serialises writers of one company).
        $db->query("CREATE OR REPLACE FUNCTION engage_licence_events_seq() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        BEGIN
            INSERT INTO engage_licence_event_counters (cmp_id, last_seq) VALUES (NEW.cmp_id, 1)
            ON CONFLICT (cmp_id) DO UPDATE SET last_seq = engage_licence_event_counters.last_seq + 1
            RETURNING last_seq INTO NEW.seq;
            RETURN NEW;
        END
        \$fn\$");
        $db->query("CREATE OR REPLACE FUNCTION engage_licence_events_append_only() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        BEGIN
            RAISE EXCEPTION 'engage_licence_events is append-only (% refused)', TG_OP;
        END
        \$fn\$");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_licence_events_seq ON engage_licence_events');
        $db->query('CREATE TRIGGER trg_engage_licence_events_seq BEFORE INSERT ON engage_licence_events FOR EACH ROW EXECUTE FUNCTION engage_licence_events_seq()');
        $db->query('DROP TRIGGER IF EXISTS trg_engage_licence_events_ro ON engage_licence_events');
        $db->query('CREATE TRIGGER trg_engage_licence_events_ro BEFORE UPDATE OR DELETE ON engage_licence_events FOR EACH ROW EXECUTE FUNCTION engage_licence_events_append_only()');
        $db->query('DROP TRIGGER IF EXISTS trg_engage_licence_events_trunc ON engage_licence_events');
        $db->query('CREATE TRIGGER trg_engage_licence_events_trunc BEFORE TRUNCATE ON engage_licence_events FOR EACH STATEMENT EXECUTE FUNCTION engage_licence_events_append_only()');

        $db->query("CREATE TABLE IF NOT EXISTS engage_licence_seats (
            id            BIGSERIAL PRIMARY KEY,
            licence_id    BIGINT       NOT NULL REFERENCES engage_licences(id) ON DELETE RESTRICT,
            licence_ref   VARCHAR(32)  NOT NULL,
            cmp_id        BIGINT       NOT NULL,
            member_ref    VARCHAR(191) NULL,
            member_label  VARCHAR(191) NULL,
            assigned_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            assigned_by   BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            released_at   TIMESTAMPTZ  NULL,
            released_by   BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            CONSTRAINT engage_licence_seats_release_chk CHECK (released_at IS NULL OR released_at >= assigned_at)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licence_seats_licence ON engage_licence_seats (licence_id, released_at)');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_licence_seats_member ON engage_licence_seats (licence_id, member_ref) WHERE released_at IS NULL AND member_ref IS NOT NULL');

        $db->query("CREATE TABLE IF NOT EXISTS engage_s2s_nonces (
            key_id   VARCHAR(64)  NOT NULL,
            nonce    VARCHAR(128) NOT NULL,
            seen_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            PRIMARY KEY (key_id, nonce)
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_s2s_nonces_seen ON engage_s2s_nonces (seen_at)');
        $db->query("CREATE TABLE IF NOT EXISTS engage_s2s_rate (
            key_id        VARCHAR(64) NOT NULL,
            window_start  TIMESTAMPTZ NOT NULL,
            hits          INTEGER     NOT NULL DEFAULT 0,
            PRIMARY KEY (key_id, window_start)
        )");
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query('DROP TABLE IF EXISTS engage_s2s_rate');
        $db->query('DROP TABLE IF EXISTS engage_s2s_nonces');
        $db->query('DROP TABLE IF EXISTS engage_licence_seats');
        $db->query('DROP TABLE IF EXISTS engage_licence_events');
        $db->query('DROP TABLE IF EXISTS engage_licence_event_counters');
        $db->query('DROP TABLE IF EXISTS engage_licences');
        $db->query('DROP SEQUENCE IF EXISTS engage_licence_ref_seq');
        $db->query('DROP TABLE IF EXISTS engage_account_companies');
        $db->query('DROP TABLE IF EXISTS engage_offers');
        $db->query('DROP TABLE IF EXISTS engage_licence_types');
        $db->query('DROP FUNCTION IF EXISTS engage_licence_events_append_only()');
        $db->query('DROP FUNCTION IF EXISTS engage_licence_events_seq()');
        $db->query('DROP FUNCTION IF EXISTS engage_offers_guard()');
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Seller programme (seller.aicountly.com) — every seller row and credential lives here, in Engage.
 * docs/SELLER_PROGRAM.md describes the lifecycle; the tables:
 *
 *   engage_seller_applications        "request to become a seller" (requested → under_review → approved | rejected)
 *   engage_sellers                    seller account (onboarding → kyc_pending → active ⇄ suspended → terminated)
 *   engage_seller_sessions            opaque session tokens (sha256 stored; 8h idle / 7d absolute)
 *   engage_seller_tokens              one-time activation / password-reset links and login OTP challenges (hash stored)
 *   engage_seller_kyc                 KYC record (PAN / GSTIN / bank account encrypted, last 4 kept for display)
 *   engage_seller_kyc_documents       uploaded KYC files (encrypted, stored outside the web root)
 *   engage_seller_agreement_versions  seller agreement text (draft → current → retired)
 *   engage_seller_agreement_acceptances  who accepted which version, when, from which IP
 *   engage_seller_referral_codes      `ref` codes (the primary one is the seller_code)
 *   engage_seller_lead_registrations  every lead registration attempt from the portal (accepted or refused)
 *   engage_idempotency_keys           replay store for Idempotency-Key on seller-portal writes
 *
 * engage_leads gains seller attribution (seller_id, 90-day protection), the Reach lead id, a
 * normalised mobile for de-duplication and first/last-touch attribution (UTM, landing page).
 *
 * Raw DDL with IF NOT EXISTS so a re-run is harmless.
 */
class CreateSellerDomain extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_applications (
            id                BIGSERIAL PRIMARY KEY,
            application_code  VARCHAR(24)  NOT NULL UNIQUE,
            name              VARCHAR(191) NOT NULL,
            email             VARCHAR(191) NOT NULL,
            mobile            VARCHAR(32)  NULL,
            organization      VARCHAR(191) NULL,
            entity_type       VARCHAR(24)  NULL,
            city              VARCHAR(96)  NULL,
            state             VARCHAR(96)  NULL,
            profile           VARCHAR(64)  NULL,
            message           TEXT         NULL,
            status            VARCHAR(16)  NOT NULL DEFAULT 'requested',
            decision_reason   TEXT         NULL,
            reviewed_by       BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            reviewed_at       TIMESTAMPTZ  NULL,
            lead_id           BIGINT       NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            seller_id         BIGINT       NULL,
            source            VARCHAR(96)  NOT NULL DEFAULT 'seller.aicountly.com',
            ip_address        VARCHAR(64)  NULL,
            user_agent        VARCHAR(255) NULL,
            metadata          JSONB        NULL,
            created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_applications_status_chk CHECK (status IN ('requested','under_review','approved','rejected'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_applications_email ON engage_seller_applications (LOWER(email))');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_applications_status ON engage_seller_applications (status, created_at)');
        // One open application per email address.
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_seller_applications_open_email
            ON engage_seller_applications (LOWER(email)) WHERE status IN ('requested','under_review')");

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_seller_code_seq START 1');
        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_seller_application_code_seq START 1');

        $db->query("CREATE TABLE IF NOT EXISTS engage_sellers (
            id                    BIGSERIAL PRIMARY KEY,
            seller_code           VARCHAR(16)  NOT NULL UNIQUE,
            application_id        BIGINT       NULL REFERENCES engage_seller_applications(id) ON DELETE SET NULL,
            lead_id               BIGINT       NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            name                  VARCHAR(191) NOT NULL,
            email                 VARCHAR(191) NOT NULL,
            mobile                VARCHAR(32)  NULL,
            organization          VARCHAR(191) NULL,
            entity_type           VARCHAR(24)  NULL,
            city                  VARCHAR(96)  NULL,
            state                 VARCHAR(96)  NULL,
            status                VARCHAR(16)  NOT NULL DEFAULT 'onboarding',
            kyc_status            VARCHAR(16)  NOT NULL DEFAULT 'not_started',
            status_reason         TEXT         NULL,
            status_changed_at     TIMESTAMPTZ  NULL,
            status_changed_by     BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            password_hash         VARCHAR(255) NULL,
            password_set_at       TIMESTAMPTZ  NULL,
            activated_at          TIMESTAMPTZ  NULL,
            failed_attempts       INTEGER      NOT NULL DEFAULT 0,
            locked_until          TIMESTAMPTZ  NULL,
            last_login_at         TIMESTAMPTZ  NULL,
            last_login_ip         VARCHAR(64)  NULL,
            agreement_version_id  BIGINT       NULL,
            agreement_accepted_at TIMESTAMPTZ  NULL,
            approved_by           BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            terminated_at         TIMESTAMPTZ  NULL,
            created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_sellers_status_chk CHECK (status IN ('onboarding','kyc_pending','active','suspended','terminated')),
            CONSTRAINT engage_sellers_kyc_status_chk CHECK (kyc_status IN ('not_started','kyc_submitted','kyc_verified','kyc_rejected'))
        )");
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_sellers_email ON engage_sellers (LOWER(email))');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_sellers_status ON engage_sellers (status)');
        $db->query("DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'engage_seller_applications_seller_fk') THEN
                ALTER TABLE engage_seller_applications ADD CONSTRAINT engage_seller_applications_seller_fk
                    FOREIGN KEY (seller_id) REFERENCES engage_sellers(id) ON DELETE SET NULL;
            END IF; END $$");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_sessions (
            id             BIGSERIAL PRIMARY KEY,
            seller_id      BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE CASCADE,
            token_hash     CHAR(64)     NOT NULL UNIQUE,
            created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            last_seen_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            expires_at     TIMESTAMPTZ  NOT NULL,
            revoked_at     TIMESTAMPTZ  NULL,
            revoked_reason VARCHAR(48)  NULL,
            ip_address     VARCHAR(64)  NULL,
            user_agent     VARCHAR(255) NULL
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_sessions_seller ON engage_seller_sessions (seller_id) WHERE revoked_at IS NULL');

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_tokens (
            id           BIGSERIAL PRIMARY KEY,
            seller_id    BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE CASCADE,
            purpose      VARCHAR(24)  NOT NULL,
            token_hash   CHAR(64)     NOT NULL UNIQUE,
            code_hash    VARCHAR(255) NULL,
            attempts     INTEGER      NOT NULL DEFAULT 0,
            expires_at   TIMESTAMPTZ  NOT NULL,
            used_at      TIMESTAMPTZ  NULL,
            revoked_at   TIMESTAMPTZ  NULL,
            created_by   BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            delivery     VARCHAR(32)  NULL,
            ip_address   VARCHAR(64)  NULL,
            created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_tokens_purpose_chk CHECK (purpose IN ('activation','password_reset','login_otp'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_tokens_seller ON engage_seller_tokens (seller_id, purpose)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_agreement_versions (
            id            BIGSERIAL PRIMARY KEY,
            version_label VARCHAR(32)  NOT NULL UNIQUE,
            title         VARCHAR(191) NOT NULL,
            body          TEXT         NOT NULL,
            status        VARCHAR(16)  NOT NULL DEFAULT 'draft',
            published_at  TIMESTAMPTZ  NULL,
            published_by  BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            retired_at    TIMESTAMPTZ  NULL,
            created_by    BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_agreement_versions_status_chk CHECK (status IN ('draft','current','retired'))
        )");
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_seller_agreement_current
            ON engage_seller_agreement_versions ((status)) WHERE status = 'current'");
        $db->query("DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'engage_sellers_agreement_fk') THEN
                ALTER TABLE engage_sellers ADD CONSTRAINT engage_sellers_agreement_fk
                    FOREIGN KEY (agreement_version_id) REFERENCES engage_seller_agreement_versions(id) ON DELETE SET NULL;
            END IF; END $$");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_agreement_acceptances (
            id                   BIGSERIAL PRIMARY KEY,
            seller_id            BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE CASCADE,
            agreement_version_id BIGINT       NOT NULL REFERENCES engage_seller_agreement_versions(id) ON DELETE RESTRICT,
            accepted_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            ip_address           VARCHAR(64)  NULL,
            user_agent           VARCHAR(255) NULL,
            UNIQUE (seller_id, agreement_version_id)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_kyc (
            id                  BIGSERIAL PRIMARY KEY,
            seller_id           BIGINT       NOT NULL UNIQUE REFERENCES engage_sellers(id) ON DELETE CASCADE,
            legal_name          VARCHAR(191) NULL,
            entity_type         VARCHAR(24)  NULL,
            pan_enc             TEXT         NULL,
            pan_last4           VARCHAR(4)   NULL,
            gstin_enc           TEXT         NULL,
            gstin_last4         VARCHAR(4)   NULL,
            gst_state_code      VARCHAR(2)   NULL,
            address_line1       VARCHAR(191) NULL,
            address_line2       VARCHAR(191) NULL,
            city                VARCHAR(96)  NULL,
            state               VARCHAR(96)  NULL,
            pincode             VARCHAR(6)   NULL,
            bank_account_holder VARCHAR(191) NULL,
            bank_account_enc    TEXT         NULL,
            bank_account_last4  VARCHAR(4)   NULL,
            bank_ifsc           VARCHAR(11)  NULL,
            status              VARCHAR(16)  NOT NULL DEFAULT 'draft',
            rejection_reason    TEXT         NULL,
            submitted_at        TIMESTAMPTZ  NULL,
            declaration_at      TIMESTAMPTZ  NULL,
            declaration_ip      VARCHAR(64)  NULL,
            submission_count    INTEGER      NOT NULL DEFAULT 0,
            reviewed_by         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            reviewed_at         TIMESTAMPTZ  NULL,
            created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_kyc_status_chk CHECK (status IN ('draft','kyc_submitted','kyc_verified','kyc_rejected')),
            CONSTRAINT engage_seller_kyc_entity_chk CHECK (entity_type IS NULL OR entity_type IN ('individual','proprietorship','partnership','llp','company'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_kyc_status ON engage_seller_kyc (status, submitted_at)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_kyc_documents (
            id            BIGSERIAL PRIMARY KEY,
            seller_id     BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE CASCADE,
            doc_type      VARCHAR(32)  NOT NULL,
            original_name VARCHAR(191) NOT NULL,
            stored_name   VARCHAR(96)  NOT NULL UNIQUE,
            mime_type     VARCHAR(64)  NOT NULL,
            size_bytes    INTEGER      NOT NULL,
            sha256        CHAR(64)     NOT NULL,
            status        VARCHAR(16)  NOT NULL DEFAULT 'current',
            uploaded_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            uploaded_ip   VARCHAR(64)  NULL,
            CONSTRAINT engage_seller_kyc_documents_type_chk CHECK (doc_type IN ('pan_card','cancelled_cheque','gst_certificate','address_proof')),
            CONSTRAINT engage_seller_kyc_documents_status_chk CHECK (status IN ('current','superseded','deleted'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_kyc_documents_seller ON engage_seller_kyc_documents (seller_id, doc_type, status)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_referral_codes (
            id          BIGSERIAL PRIMARY KEY,
            seller_id   BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE CASCADE,
            code        VARCHAR(32)  NOT NULL,
            is_primary  BOOLEAN      NOT NULL DEFAULT TRUE,
            status      VARCHAR(16)  NOT NULL DEFAULT 'active',
            created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_referral_codes_status_chk CHECK (status IN ('active','disabled'))
        )");
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_seller_referral_codes_code ON engage_seller_referral_codes (UPPER(code))');

        // Lead attribution columns.
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS seller_id BIGINT NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS seller_attribution VARCHAR(16) NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS seller_attributed_at TIMESTAMPTZ NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS seller_protection_expires_at TIMESTAMPTZ NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS reach_lead_id BIGINT NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS mobile_normalized VARCHAR(16) NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS attribution JSONB NULL');
        $db->query('ALTER TABLE engage_leads ADD COLUMN IF NOT EXISTS converted_at TIMESTAMPTZ NULL');
        $db->query("DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'engage_leads_seller_fk') THEN
                ALTER TABLE engage_leads ADD CONSTRAINT engage_leads_seller_fk
                    FOREIGN KEY (seller_id) REFERENCES engage_sellers(id) ON DELETE SET NULL;
            END IF; END $$");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_leads_email_lower ON engage_leads (LOWER(email))');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_leads_mobile_normalized ON engage_leads (mobile_normalized)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_leads_seller ON engage_leads (seller_id)');
        // Backfill the normalised mobile (last 10 digits) for existing leads.
        $db->query("UPDATE engage_leads SET mobile_normalized = RIGHT(regexp_replace(COALESCE(mobile, whatsapp, ''), '\\D', '', 'g'), 10)
                     WHERE mobile_normalized IS NULL AND length(regexp_replace(COALESCE(mobile, whatsapp, ''), '\\D', '', 'g')) >= 10");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_lead_registrations (
            id                    BIGSERIAL PRIMARY KEY,
            seller_id             BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE CASCADE,
            lead_id               BIGINT       NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            outcome               VARCHAR(24)  NOT NULL,
            reason                VARCHAR(255) NULL,
            email                 VARCHAR(191) NULL,
            mobile_normalized     VARCHAR(16)  NULL,
            protection_expires_at TIMESTAMPTZ  NULL,
            created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_seller_lead_registrations_outcome_chk CHECK (outcome IN ('accepted','claimed','rejected_duplicate','rejected_protected','rejected_customer'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_seller_lead_registrations_seller ON engage_seller_lead_registrations (seller_id, created_at)');

        $db->query("CREATE TABLE IF NOT EXISTS engage_idempotency_keys (
            id              BIGSERIAL PRIMARY KEY,
            scope           VARCHAR(160) NOT NULL,
            idem_key        VARCHAR(128) NOT NULL,
            request_hash    CHAR(64)     NOT NULL,
            response_status INTEGER      NULL,
            response_body   TEXT         NULL,
            created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            UNIQUE (scope, idem_key)
        )");

        // Lead sources for the new source types.
        $db->query("INSERT INTO engage_lead_sources (code, name, source_type, source_portal, default_weight, is_active, description, created_at, updated_at)
            VALUES ('seller_request', 'Seller programme request', 'seller_request', 'seller.aicountly.com', 10, TRUE, 'Someone asking to become an AICOUNTLY seller (seller.aicountly.com request form).', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                   ('seller', 'Seller registered lead', 'seller', 'seller.aicountly.com', 30, TRUE, 'Lead registered by an AICOUNTLY seller on seller.aicountly.com.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (code) DO NOTHING");

        // Settings (seeders never run again on existing deployments).
        $settings = [
            ['seller_login_otp_enabled', false, 'Ask sellers for an emailed one-time code after their password. Only applies when a seller mailer is configured (ENGAGE_SELLER_MAILER).'],
            ['seller_lead_protection_days', 90, 'Days a seller-registered (or referred) lead stays protected for that seller.'],
            ['commission_default_hold_days', 15, 'Default hold (refund / clawback window) for new commission plans, in days.'],
            ['seller_payout_min_threshold', '500.00', 'Minimum weekly payout per seller in INR (below it rolls over). Shown on the seller dashboard; used by weekly payouts once they are built.'],
        ];
        foreach ($settings as [$key, $value, $desc]) {
            $db->query('INSERT INTO engage_settings (key, value_json, description, created_at, updated_at)
                        VALUES (?, CAST(? AS JSONB), ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (key) DO NOTHING',
                [$key, json_encode($value), $desc]);
        }
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query("DELETE FROM engage_settings WHERE key IN ('seller_login_otp_enabled','seller_lead_protection_days','commission_default_hold_days','seller_payout_min_threshold')");
        $db->query("DELETE FROM engage_lead_sources WHERE code IN ('seller_request','seller')");
        foreach (['engage_idempotency_keys', 'engage_seller_lead_registrations'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('ALTER TABLE engage_leads DROP CONSTRAINT IF EXISTS engage_leads_seller_fk');
        foreach (['seller_id', 'seller_attribution', 'seller_attributed_at', 'seller_protection_expires_at', 'reach_lead_id', 'mobile_normalized', 'attribution', 'converted_at'] as $c) {
            $db->query("ALTER TABLE engage_leads DROP COLUMN IF EXISTS {$c}");
        }
        $db->query('DROP INDEX IF EXISTS idx_engage_leads_email_lower');
        foreach ([
            'engage_seller_referral_codes', 'engage_seller_kyc_documents', 'engage_seller_kyc',
            'engage_seller_agreement_acceptances', 'engage_seller_tokens', 'engage_seller_sessions',
        ] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('ALTER TABLE engage_seller_applications DROP CONSTRAINT IF EXISTS engage_seller_applications_seller_fk');
        $db->query('DROP TABLE IF EXISTS engage_sellers');
        $db->query('DROP TABLE IF EXISTS engage_seller_agreement_versions');
        $db->query('DROP TABLE IF EXISTS engage_seller_applications');
        $db->query('DROP SEQUENCE IF EXISTS engage_seller_code_seq');
        $db->query('DROP SEQUENCE IF EXISTS engage_seller_application_code_seq');
    }
}

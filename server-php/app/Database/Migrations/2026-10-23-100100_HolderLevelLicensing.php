<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Holder-level licences and the Basic plan (contract §3.1, founder decisions D-L1 / D-L2; docs/LICENSING.md).
 * Additive: every existing licence, event and offer keeps working unchanged.
 *
 *   engage_licences          + holder_uuid (my.aicountly uuid of the account owner), cmp_id and account_id become
 *                              nullable (a licence names a holder, a company, or both; a provisioned Basic has no
 *                              Engage account), + is_basic (issued from the product's default offer), + is_addon
 *                              (explicit second live licence), + idempotency_key / request_hash (issue replay),
 *                              + superseded_at, + holder_note. Status gains `superseded` (a Basic while a paid
 *                              licence of the same holder + product is active); source gains `provisioning`.
 *                              At most one live Basic per (holder_uuid, product_code) — uq_engage_licences_basic_live.
 *   engage_licence_events    cmp_id nullable + holder_uuid; seq is per cmp_id as before, and per holder_uuid for
 *                              an event of a licence without a company (engage_licence_holder_event_counters).
 *                              Types gain plan_changed, superseded, reactivated.
 *   engage_licence_seats     cmp_id nullable.
 *   engage_offers            + is_default: the product's Basic plan. At most ONE published default offer per
 *                              product_code (uq_engage_offers_default). is_default is not part of the published-offer
 *                              immutability guard, so staff can toggle it on a published version.
 *   engage_holders           directory of my.aicountly holders Engage has heard of (provisioning, staff issue):
 *                              uuid, e-mail, name — the staff "find a holder" lookup.
 */
class HolderLevelLicensing extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS holder_uuid VARCHAR(64) NULL');
        $db->query('ALTER TABLE engage_licences ALTER COLUMN cmp_id DROP NOT NULL');
        $db->query('ALTER TABLE engage_licences ALTER COLUMN account_id DROP NOT NULL');
        $db->query('ALTER TABLE engage_licences ALTER COLUMN source TYPE VARCHAR(16)');
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS is_basic BOOLEAN NOT NULL DEFAULT FALSE');
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS is_addon BOOLEAN NOT NULL DEFAULT FALSE');
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(128) NULL');
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS request_hash VARCHAR(64) NULL');
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS superseded_at TIMESTAMPTZ NULL');
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS holder_note TEXT NULL');

        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_status_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_status_chk
            CHECK (status IN ('issued','active','suspended','expired','revoked','superseded'))");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_source_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_source_chk CHECK (source IN ('staff','order','provisioning'))");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_subject_chk');
        $db->query('ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_subject_chk CHECK (cmp_id IS NOT NULL OR holder_uuid IS NOT NULL)');
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_holder_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_holder_chk CHECK (holder_uuid IS NULL OR holder_uuid ~ '^[A-Za-z0-9._:\\-]{1,64}$')");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_account_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_account_chk CHECK (account_id IS NOT NULL OR source = 'provisioning')");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_superseded_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_superseded_chk CHECK (status <> 'superseded' OR is_basic)");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_basic_chk');
        $db->query('ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_basic_chk CHECK (NOT is_basic OR holder_uuid IS NOT NULL)');

        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_licences_idem ON engage_licences (idempotency_key) WHERE idempotency_key IS NOT NULL');
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_licences_basic_live ON engage_licences (holder_uuid, product_code)
            WHERE is_basic AND status IN ('issued','active','suspended','superseded')");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licences_holder ON engage_licences (holder_uuid, product_code) WHERE holder_uuid IS NOT NULL');

        // Events: a licence without a company numbers its events per holder.
        $db->query('ALTER TABLE engage_licence_events ALTER COLUMN cmp_id DROP NOT NULL');
        $db->query('ALTER TABLE engage_licence_events ADD COLUMN IF NOT EXISTS holder_uuid VARCHAR(64) NULL');
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_subject_chk');
        $db->query('ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_subject_chk CHECK (cmp_id IS NOT NULL OR holder_uuid IS NOT NULL)');
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_type_chk');
        $db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_type_chk CHECK (type IN ('issued','activated','renewed','extended','upgraded',
            'suspended','reinstated','expired','revoked','seats_changed','seat_assigned','seat_released','invoice_linked',
            'plan_changed','superseded','reactivated'))");
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_licence_events_holder_seq ON engage_licence_events (holder_uuid, seq) WHERE cmp_id IS NULL');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licence_events_holder ON engage_licence_events (holder_uuid, occurred_at DESC, id DESC) WHERE holder_uuid IS NOT NULL');
        $db->query("CREATE TABLE IF NOT EXISTS engage_licence_holder_event_counters (
            holder_uuid VARCHAR(64) PRIMARY KEY,
            last_seq    BIGINT      NOT NULL
        )");
        $db->query("CREATE OR REPLACE FUNCTION engage_licence_events_seq() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        BEGIN
            IF NEW.cmp_id IS NOT NULL THEN
                INSERT INTO engage_licence_event_counters (cmp_id, last_seq) VALUES (NEW.cmp_id, 1)
                ON CONFLICT (cmp_id) DO UPDATE SET last_seq = engage_licence_event_counters.last_seq + 1
                RETURNING last_seq INTO NEW.seq;
            ELSE
                INSERT INTO engage_licence_holder_event_counters (holder_uuid, last_seq) VALUES (NEW.holder_uuid, 1)
                ON CONFLICT (holder_uuid) DO UPDATE SET last_seq = engage_licence_holder_event_counters.last_seq + 1
                RETURNING last_seq INTO NEW.seq;
            END IF;
            RETURN NEW;
        END
        \$fn\$");

        $db->query('ALTER TABLE engage_licence_seats ALTER COLUMN cmp_id DROP NOT NULL');

        $db->query('ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS is_default BOOLEAN NOT NULL DEFAULT FALSE');
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_offers_default ON engage_offers (product_code) WHERE is_default AND status = 'published'");

        $db->query("CREATE TABLE IF NOT EXISTS engage_holders (
            holder_uuid          VARCHAR(64)  PRIMARY KEY,
            email                VARCHAR(255) NULL,
            name                 VARCHAR(191) NULL,
            signed_up_at         TIMESTAMPTZ  NULL,
            source               VARCHAR(16)  NOT NULL DEFAULT 'myaicountly',
            last_request_id      VARCHAR(64)  NULL,
            first_provisioned_at TIMESTAMPTZ  NULL,
            last_provisioned_at  TIMESTAMPTZ  NULL,
            created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_holders_uuid_chk   CHECK (holder_uuid ~ '^[A-Za-z0-9._:\\-]{1,64}$'),
            CONSTRAINT engage_holders_source_chk CHECK (source IN ('myaicountly','staff'))
        )");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_holders_email ON engage_holders (LOWER(email))');
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query('DROP TABLE IF EXISTS engage_holders');
        $db->query('DROP INDEX IF EXISTS uq_engage_offers_default');
        $db->query('ALTER TABLE engage_offers DROP COLUMN IF EXISTS is_default');

        // The Phase A seq function (per cmp_id only). Columns stay nullable: re-adding NOT NULL would fail on
        // holder-only rows, and nothing in Phase A writes a null.
        $db->query("CREATE OR REPLACE FUNCTION engage_licence_events_seq() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        BEGIN
            INSERT INTO engage_licence_event_counters (cmp_id, last_seq) VALUES (NEW.cmp_id, 1)
            ON CONFLICT (cmp_id) DO UPDATE SET last_seq = engage_licence_event_counters.last_seq + 1
            RETURNING last_seq INTO NEW.seq;
            RETURN NEW;
        END
        \$fn\$");
        $db->query('DROP TABLE IF EXISTS engage_licence_holder_event_counters');
        $db->query('DROP INDEX IF EXISTS idx_engage_licence_events_holder');
        $db->query('DROP INDEX IF EXISTS uq_engage_licence_events_holder_seq');
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_type_chk');
        $db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_type_chk CHECK (type IN ('issued','activated','renewed','extended','upgraded',
            'suspended','reinstated','expired','revoked','seats_changed','seat_assigned','seat_released','invoice_linked')) NOT VALID");
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_subject_chk');
        $db->query('ALTER TABLE engage_licence_events DROP COLUMN IF EXISTS holder_uuid');

        $db->query('DROP INDEX IF EXISTS idx_engage_licences_holder');
        $db->query('DROP INDEX IF EXISTS uq_engage_licences_basic_live');
        $db->query('DROP INDEX IF EXISTS uq_engage_licences_idem');
        foreach (['basic', 'superseded', 'account', 'holder', 'subject'] as $c) {
            $db->query("ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_{$c}_chk");
        }
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_source_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_source_chk CHECK (source IN ('staff','order')) NOT VALID");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_status_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_status_chk CHECK (status IN ('issued','active','suspended','expired','revoked')) NOT VALID");
        foreach (['holder_note', 'superseded_at', 'request_hash', 'idempotency_key', 'is_addon', 'is_basic', 'holder_uuid'] as $c) {
            $db->query("ALTER TABLE engage_licences DROP COLUMN IF EXISTS {$c}");
        }
    }
}

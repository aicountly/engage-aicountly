<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Price revisions with an effective date — "with effect from" (docs/SELLING_LICENCES.md §2, §4). Additive.
 *
 *   engage_offers   status gains `scheduled`: a version approved to go on sale at its valid_from, the effective date.
 *                   While it is scheduled the version before it stays on sale; from valid_from the scheduled version is
 *                   the one on sale (OfferService reads are correct at that instant) and `engage:offers-activate`
 *                   records it: the old version retired at the effective date, the new one published. Before this, a
 *                   version published with a future valid_from retired the current price at once and left nothing on
 *                   sale until that date.
 *                   + scheduled_at / scheduled_by   who approved the revision, and when
 *                   + revision_note                 why the price changed (internal)
 *                   + retired_reason                why a version was retired (e.g. a revision withdrawn before it took effect)
 *                   At most one scheduled version per offer_code (uq_engage_offers_scheduled); a scheduled version
 *                   always has a valid_from.
 *
 * engage_offers_guard now covers scheduled versions: once a version leaves draft its commercial columns never change;
 * published_at / published_by are written once, when the version first goes on sale (draft → published, or
 * scheduled → published); the only moves are scheduled → published | retired and published → retired; only a draft
 * can be deleted. is_default (and channels, next migration) stay mutable, as before.
 */
class ScheduledOfferRevisions extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS scheduled_at TIMESTAMPTZ NULL');
        $db->query('ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS scheduled_by BIGINT NULL REFERENCES engage_users(id) ON DELETE SET NULL');
        $db->query('ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS revision_note TEXT NULL');
        $db->query('ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS retired_reason TEXT NULL');

        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_status_chk');
        $db->query("ALTER TABLE engage_offers ADD CONSTRAINT engage_offers_status_chk CHECK (status IN ('draft','scheduled','published','retired'))");
        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_scheduled_chk');
        $db->query("ALTER TABLE engage_offers ADD CONSTRAINT engage_offers_scheduled_chk CHECK (status <> 'scheduled' OR (valid_from IS NOT NULL AND scheduled_at IS NOT NULL))");
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_offers_scheduled ON engage_offers (offer_code) WHERE status = 'scheduled'");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_offers_due ON engage_offers (valid_from) WHERE status = 'scheduled'");

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
                    NEW.created_by, NEW.created_at, NEW.scheduled_at, NEW.scheduled_by, NEW.revision_note)
                   IS DISTINCT FROM
                   (OLD.offer_code, OLD.version, OLD.product_code, OLD.licence_type_code, OLD.plan_code, OLD.label,
                    OLD.description, OLD.price_paise, OLD.currency, OLD.price_basis, OLD.gst_rate, OLD.sac, OLD.term_months,
                    OLD.term_days, OLD.seats, OLD.app_keys, OLD.entitlements, OLD.display, OLD.valid_from, OLD.valid_to,
                    OLD.created_by, OLD.created_at, OLD.scheduled_at, OLD.scheduled_by, OLD.revision_note) THEN
                    RAISE EXCEPTION 'engage_offers: % v% is % and immutable — create a new version', OLD.offer_code, OLD.version, OLD.status;
                END IF;
                IF OLD.published_at IS NOT NULL
                   AND (NEW.published_at, NEW.published_by) IS DISTINCT FROM (OLD.published_at, OLD.published_by) THEN
                    RAISE EXCEPTION 'engage_offers: % v% went on sale at % and that cannot change', OLD.offer_code, OLD.version, OLD.published_at;
                END IF;
                IF OLD.published_at IS NULL AND NEW.published_at IS NOT NULL
                   AND NOT (OLD.status = 'scheduled' AND NEW.status = 'published') THEN
                    RAISE EXCEPTION 'engage_offers: % v% — published_at is written only when the version goes on sale', OLD.offer_code, OLD.version;
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                       (OLD.status = 'published' AND NEW.status = 'retired')
                    OR (OLD.status = 'scheduled' AND NEW.status IN ('published', 'retired'))) THEN
                    RAISE EXCEPTION 'engage_offers: % v% cannot move from % to %', OLD.offer_code, OLD.version, OLD.status, NEW.status;
                END IF;
            END IF;
            RETURN NEW;
        END
        \$fn\$");
    }

    public function down(): void
    {
        $db = $this->db;
        // A scheduled version never went on sale: retire it so the old status list holds again.
        $db->query("UPDATE engage_offers SET status = 'retired', retired_at = NOW(), retired_reason = COALESCE(retired_reason, 'Rolled back before it took effect') WHERE status = 'scheduled'");
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
        $db->query('DROP INDEX IF EXISTS idx_engage_offers_due');
        $db->query('DROP INDEX IF EXISTS uq_engage_offers_scheduled');
        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_scheduled_chk');
        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_status_chk');
        $db->query("ALTER TABLE engage_offers ADD CONSTRAINT engage_offers_status_chk CHECK (status IN ('draft','published','retired'))");
        foreach (['retired_reason', 'revision_note', 'scheduled_by', 'scheduled_at'] as $c) {
            $db->query("ALTER TABLE engage_offers DROP COLUMN IF EXISTS {$c}");
        }
    }
}

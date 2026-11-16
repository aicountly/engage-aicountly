<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Licences for one company, or for the whole workspace (docs/LICENSING.md §3.6). Additive: every existing offer and
 * licence keeps meaning exactly what it meant.
 *
 *   engage_offers.licence_scope   workspace (default — every company the customer owns, as every licence so far) |
 *                                 company (one company at a time: the customer chooses which in Manage and may move it).
 *                                 It is part of what is sold, so it is immutable once a version leaves draft
 *                                 (engage_offers_guard). A product's Basic (is_default) is always workspace.
 *   engage_licences.scope         workspace | company, set at issue from the offer. A licence with no holder (a legacy
 *                                 company-level licence) is company. A company licence of a holder names the company it
 *                                 applies to in cmp_id, or NULL while it is not applied to one yet (it then grants
 *                                 nothing). For a workspace licence cmp_id keeps its old meaning: the company the sale
 *                                 was recorded against, for information.
 *   engage_licence_events         type company_changed (a company licence applied to, moved to or taken off a company);
 *                                 actor kind customer (the holder, acting in Manage).
 */
class LicenceScopes extends Migration
{
    private const TYPES = "'issued','activated','renewed','extended','upgraded','suspended','reinstated','expired','revoked',"
        . "'seats_changed','seat_assigned','seat_released','invoice_linked','plan_changed','superseded','reactivated'";

    public function up(): void
    {
        $db = $this->db;

        $db->query("ALTER TABLE engage_offers ADD COLUMN IF NOT EXISTS licence_scope VARCHAR(16) NOT NULL DEFAULT 'workspace'");
        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_scope_chk');
        $db->query("ALTER TABLE engage_offers ADD CONSTRAINT engage_offers_scope_chk CHECK (licence_scope IN ('workspace','company') AND (NOT is_default OR licence_scope = 'workspace'))");

        $db->query("ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS scope VARCHAR(16) NOT NULL DEFAULT 'workspace'");
        // A licence with no holder has always applied to its one company.
        $db->query("UPDATE engage_licences SET scope = 'company' WHERE holder_uuid IS NULL AND scope <> 'company'");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_scope_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_scope_chk CHECK (scope IN ('workspace','company')
            AND (scope = 'company' OR holder_uuid IS NOT NULL) AND (scope = 'workspace' OR NOT is_basic))");
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_licences_company_scope ON engage_licences (cmp_id, product_code) WHERE scope = 'company' AND cmp_id IS NOT NULL");
        // Any writer that does not know about scope (an older code path, a data fix) still writes a consistent row: a licence
        // with no holder is for its one company.
        $db->query("CREATE OR REPLACE FUNCTION engage_licences_scope_default() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
        BEGIN
            IF NEW.holder_uuid IS NULL OR NEW.holder_uuid = '' THEN
                NEW.scope := 'company';
            END IF;
            RETURN NEW;
        END
        \$fn\$");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_licences_scope_default ON engage_licences');
        $db->query('CREATE TRIGGER trg_engage_licences_scope_default BEFORE INSERT OR UPDATE OF holder_uuid, scope ON engage_licences FOR EACH ROW EXECUTE FUNCTION engage_licences_scope_default()');

        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_type_chk');
        $db->query('ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_type_chk CHECK (type IN (' . self::TYPES . ",'company_changed'))");
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_actor_chk');
        $db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_actor_chk CHECK (actor_kind IN ('staff','system','order','seller','customer'))");

        $this->guard(true);
    }

    public function down(): void
    {
        $db = $this->db;
        $this->guard(false);
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_actor_chk');
        $db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_actor_chk CHECK (actor_kind IN ('staff','system','order','seller')) NOT VALID");
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_type_chk');
        $db->query('ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_type_chk CHECK (type IN (' . self::TYPES . ')) NOT VALID');
        $db->query('DROP TRIGGER IF EXISTS trg_engage_licences_scope_default ON engage_licences');
        $db->query('DROP FUNCTION IF EXISTS engage_licences_scope_default()');
        $db->query('DROP INDEX IF EXISTS idx_engage_licences_company_scope');
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_scope_chk');
        $db->query('ALTER TABLE engage_licences DROP COLUMN IF EXISTS scope');
        $db->query('ALTER TABLE engage_offers DROP CONSTRAINT IF EXISTS engage_offers_scope_chk');
        $db->query('ALTER TABLE engage_offers DROP COLUMN IF EXISTS licence_scope');
    }

    /** engage_offers_guard as 2026-11-15-000100 left it, with licence_scope among the columns that never change once a version leaves draft. */
    private function guard(bool $withScope): void
    {
        $new = $withScope ? ', NEW.licence_scope' : '';
        $old = $withScope ? ', OLD.licence_scope' : '';
        $this->db->query("CREATE OR REPLACE FUNCTION engage_offers_guard() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
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
                    NEW.created_by, NEW.created_at, NEW.scheduled_at, NEW.scheduled_by, NEW.revision_note{$new})
                   IS DISTINCT FROM
                   (OLD.offer_code, OLD.version, OLD.product_code, OLD.licence_type_code, OLD.plan_code, OLD.label,
                    OLD.description, OLD.price_paise, OLD.currency, OLD.price_basis, OLD.gst_rate, OLD.sac, OLD.term_months,
                    OLD.term_days, OLD.seats, OLD.app_keys, OLD.entitlements, OLD.display, OLD.valid_from, OLD.valid_to,
                    OLD.created_by, OLD.created_at, OLD.scheduled_at, OLD.scheduled_by, OLD.revision_note{$old}) THEN
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
}

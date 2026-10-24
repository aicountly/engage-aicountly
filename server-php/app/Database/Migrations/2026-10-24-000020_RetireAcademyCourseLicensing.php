<?php

namespace App\Database\Migrations;

use App\Libraries\Licensing\ManageAppRegistry;
use CodeIgniter\Database\Migration;
use Config\Services;

/**
 * Aicountly Edge and Aicountly Aspire are Aicountly Academy courses, never licensed to companies (owner decision,
 * 2026-10-24; ManageAppRegistry::COURSE_KEYS = academy, edge, aspire). The engage_products rows stay (leads, fee
 * payments and seller commission use them); this removes what Phase 5 licensing may have left behind:
 *
 *   engage_offers             a draft or published offer OF a course product (product_code in COURSE_KEYS, or a
 *                             product of category Academy), or a published offer of another product whose app_keys
 *                             name a course key → status `retired` (published → retired is the one change the
 *                             immutability trigger allows; drafts are retired too, not deleted, so they stay
 *                             visible). A DRAFT of another product only loses the course keys from its app_keys.
 *   engage_entitlement_outbox a pending push for app_key edge / aspire / academy → `skipped` (Engage no longer
 *                             accepts those keys; Manage would be asked for a course plan).
 *   engage_licences           NOT changed. Licences already issued for a course are logged (warning, and on the
 *                             console when run by `php spark migrate`) for the owner. There is no non-renewing
 *                             status: LicenceService refuses activate / renew / extend / change-offer on them
 *                             (409 course_not_licensable); staff may still suspend, revoke or let them expire.
 *                             docs/LICENSING.md § Aicountly Academy courses has the query that lists them.
 *
 * No entitlement rows were ever seeded by a migration (the edge / aspire codes lived only in the PHP catalogue,
 * removed in the same change). Idempotent: a second run finds nothing to do. down() changes nothing — a retired
 * offer cannot be published again (trigger), so the owner re-creates an offer if the decision is ever reversed.
 */
class RetireAcademyCourseLicensing extends Migration
{
    private const REASON = 'Aicountly Academy course: not licensable (2026-10-24)';

    public function up(): void
    {
        $db = $this->db;
        $list = ManageAppRegistry::courseKeysSql();
        $courseProduct = "(o.product_code IN ({$list}) OR EXISTS (SELECT 1 FROM engage_products p WHERE p.code = o.product_code AND LOWER(p.category) = 'academy'))";

        // 1. Retire course offers (draft or published) and published offers of other products that name a course key.
        $retired = $db->query(
            "UPDATE engage_offers o SET status = 'retired', retired_at = COALESCE(o.retired_at, NOW()), updated_at = NOW()
              WHERE o.status IN ('draft','published')
                AND ({$courseProduct} OR (o.status = 'published' AND o.app_keys && ARRAY[{$list}]::text[]))
          RETURNING o.id, o.offer_code, o.version, o.product_code"
        )->getResultArray();

        // 2. Drafts of other products keep their work but lose the course keys.
        $stripped = $db->query(
            "UPDATE engage_offers o SET app_keys = ARRAY(SELECT k FROM unnest(o.app_keys) k WHERE k NOT IN ({$list})), updated_at = NOW()
              WHERE o.status = 'draft' AND o.app_keys && ARRAY[{$list}]::text[]
          RETURNING o.id, o.offer_code, o.version"
        )->getResultArray();

        foreach ($retired as $o) {
            $this->audit('offer.retired', (int) $o['id'], ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'product_code' => $o['product_code']]);
        }
        foreach ($stripped as $o) {
            $this->audit('offer.updated', (int) $o['id'], ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'removed_app_keys' => ManageAppRegistry::COURSE_KEYS]);
        }

        // 3. Pending entitlement pushes for a course key are skipped.
        $skipped = $db->query(
            "UPDATE engage_entitlement_outbox SET status = 'skipped', last_error = ? WHERE status = 'pending' AND app_key IN ({$list}) RETURNING id",
            [self::REASON]
        )->getResultArray();

        // 4. Licences already issued for a course: report only.
        $licences = $db->query(
            "SELECT licence_ref, product_code, status, cmp_id, holder_uuid, offer_code, ends_at FROM engage_licences
              WHERE product_code IN ({$list}) OR app_keys && ARRAY[{$list}]::text[] ORDER BY id"
        )->getResultArray();

        $summary = sprintf('RetireAcademyCourseLicensing: %d offer(s) retired, %d draft(s) lost course app keys, %d pending push(es) skipped, %d course licence(s) found.',
            count($retired), count($stripped), count($skipped), count($licences));
        $lines = [$summary];
        foreach ($retired as $o) {
            $lines[] = "  offer retired: {$o['offer_code']} v{$o['version']} ({$o['product_code']})";
        }
        foreach ($licences as $l) {
            $lines[] = "  licence (left as is, cannot be renewed): {$l['licence_ref']} {$l['product_code']} {$l['status']} cmp=" . ($l['cmp_id'] ?? '-')
                . ' holder=' . ($l['holder_uuid'] ?? '-') . " offer={$l['offer_code']} ends=" . ($l['ends_at'] ?? 'open');
        }
        log_message($licences === [] ? 'info' : 'warning', implode("\n", $lines));
        if (is_cli() && ENVIRONMENT !== 'testing') {
            foreach ($lines as $line) {
                echo $line, PHP_EOL;
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: a retired offer cannot be published again (engage_offers_guard).
    }

    private function audit(string $event, int $offerId, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'offer', 'subject_id' => $offerId, 'actor_email' => 'system:engage', 'actor_role' => 'system',
            'metadata' => $meta + ['reason' => self::REASON, 'by' => 'migration 2026-10-24-000020_RetireAcademyCourseLicensing'], 'fanout_console' => true]);
    }
}

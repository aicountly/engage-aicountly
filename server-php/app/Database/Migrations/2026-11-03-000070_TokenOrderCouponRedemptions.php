<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A coupon used on a token purchase is recorded like any other coupon use.
 *
 * WHY. engage_coupon_redemptions is the estate's one record of who used which coupon, for
 * how much, and whether it was spent — it is what enforces "once per customer", what makes a
 * coupon run out, and what sales reads to see what a campaign gave away. Token purchases
 * were writing the coupon onto the order and nothing else, so a coupon marked single-use
 * could be used on every purchase a customer ever made, `max_redemptions_total` never
 * counted down, and a campaign's cost was invisible. This lets a token order sit in that
 * table beside the licence redemptions.
 *
 * `licence_ref` is what a committed redemption was committed TO, and a purchase of tokens is
 * not a licence. So `order_ref` is added beside it and the commit check now wants one or the
 * other — never neither, which is the thing it was protecting against: a redemption counted
 * as spent with no record of what spent it.
 *
 * Nothing is backfilled. Purchases made before this have no redemption row to find, and
 * inventing one would be inventing a fact about a customer's coupon.
 */
class TokenOrderCouponRedemptions extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('ALTER TABLE engage_coupon_redemptions ADD COLUMN IF NOT EXISTS order_ref VARCHAR(24) NULL');
        $db->query("COMMENT ON COLUMN engage_coupon_redemptions.order_ref IS 'The token order this redemption was committed to (engage_token_orders.order_ref); NULL for a licence redemption, which uses licence_ref.'");

        // A committed or reversed redemption must name what it was committed to — a licence
        // or, now, a token order.
        $db->query('ALTER TABLE engage_coupon_redemptions DROP CONSTRAINT IF EXISTS engage_coupon_redemptions_commit_chk');
        $db->query("ALTER TABLE engage_coupon_redemptions ADD CONSTRAINT engage_coupon_redemptions_commit_chk
            CHECK (state NOT IN ('committed','reversed') OR (committed_at IS NOT NULL AND (licence_ref IS NOT NULL OR order_ref IS NOT NULL)))");

        // One redemption per order, whatever Finance retries.
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_coupon_redemptions_order ON engage_coupon_redemptions (order_ref) WHERE order_ref IS NOT NULL');
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query('DROP INDEX IF EXISTS uq_engage_coupon_redemptions_order');
        $db->query('ALTER TABLE engage_coupon_redemptions DROP CONSTRAINT IF EXISTS engage_coupon_redemptions_commit_chk');
        // Back to the licence-only rule, which a row holding only an order_ref would fail. Those
        // rows are released — a released redemption is a coupon given back, which is the safe
        // direction to be wrong in. committed → released is a transition the state guard
        // refuses, deliberately, so it is stood down for exactly this statement: a migration
        // undoing a schema change is the one place that is a considered act rather than a bug.
        $db->query('ALTER TABLE engage_coupon_redemptions DISABLE TRIGGER trg_engage_coupon_redemptions_guard');
        try {
            $db->query("UPDATE engage_coupon_redemptions SET state = 'released', released_at = COALESCE(released_at, NOW()),
                release_reason = COALESCE(release_reason, 'token order redemptions are not supported by this schema'), updated_at = NOW()
                WHERE state IN ('committed','reversed') AND licence_ref IS NULL AND order_ref IS NOT NULL");
        } finally {
            $db->query('ALTER TABLE engage_coupon_redemptions ENABLE TRIGGER trg_engage_coupon_redemptions_guard');
        }
        $db->query("ALTER TABLE engage_coupon_redemptions ADD CONSTRAINT engage_coupon_redemptions_commit_chk
            CHECK (state NOT IN ('committed','reversed') OR (committed_at IS NOT NULL AND licence_ref IS NOT NULL))");
        $db->query('ALTER TABLE engage_coupon_redemptions DROP COLUMN IF EXISTS order_ref');
    }
}

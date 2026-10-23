<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Four-eyes on coupon activation also covers whoever EDITED the draft (security audit, Oct 2026).
 *
 * Before: only created_by was compared with the approver, so a sales_manager could open a colleague's draft, change the
 * discount, then activate it themselves (created_by ≠ approver). engage_coupons.edited_by records every staff id that
 * changed the draft; CouponService::activate refuses an approver who created OR edited it (a super_admin may still
 * approve their own, as documented). The column is not in the guard's lifecycle list, so it is frozen once the coupon
 * leaves draft like every other rule column. Additive: existing rows get an empty list (= today's behaviour).
 */
class CouponDraftEditors extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE engage_coupons ADD COLUMN IF NOT EXISTS edited_by BIGINT[] NOT NULL DEFAULT '{}'");
    }

    public function down(): void
    {
        // The immutability guard refuses a change to non-draft rows; dropping a column is DDL and is not blocked by it.
        $this->db->query('ALTER TABLE engage_coupons DROP COLUMN IF EXISTS edited_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AI credits for one company, or for the whole workspace (docs/TOKEN_CONVERSIONS.md §4.3). Additive.
 *
 *   engage_token_orders.credit_scope   workspace (default: every company of the owner, as before) | company
 *   engage_token_orders.credit_cmp_id  the company whose credits these are (company scope only). The BILLING company
 *                                      stays cmp_id: the invoice is made out to it and shows under its Invoices, whichever
 *                                      scope the credits have.
 *   engage_token_orders.company_name   the billing company's name as Manage gave it, for the purchase receipt.
 *   engage_token_grants.cmp_id         company credits (null = workspace credits), pushed to AI Pulse with the grant.
 *
 * One open order per holder becomes one open order per (holder, scope, credit company): a customer may pay for this
 * company's credits and the workspace's credits at the same time, but never for the same thing twice.
 */
class TokenCreditScopes extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $db->query("ALTER TABLE engage_token_orders ADD COLUMN IF NOT EXISTS credit_scope VARCHAR(16) NOT NULL DEFAULT 'workspace'");
        $db->query('ALTER TABLE engage_token_orders ADD COLUMN IF NOT EXISTS credit_cmp_id BIGINT NULL');
        $db->query('ALTER TABLE engage_token_orders ADD COLUMN IF NOT EXISTS company_name VARCHAR(200) NULL');
        $db->query('ALTER TABLE engage_token_orders DROP CONSTRAINT IF EXISTS engage_token_orders_scope_chk');
        $db->query("ALTER TABLE engage_token_orders ADD CONSTRAINT engage_token_orders_scope_chk CHECK (
            (credit_scope = 'workspace' AND credit_cmp_id IS NULL) OR (credit_scope = 'company' AND credit_cmp_id IS NOT NULL AND credit_cmp_id > 0))");

        $db->query('DROP INDEX IF EXISTS uq_engage_token_orders_open');
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_orders_open
            ON engage_token_orders (holder_uuid, credit_scope, COALESCE(credit_cmp_id, 0)) WHERE status IN ('quoted','awaiting_payment')");

        $db->query('ALTER TABLE engage_token_grants ADD COLUMN IF NOT EXISTS cmp_id BIGINT NULL');
        $db->query('ALTER TABLE engage_token_grants DROP CONSTRAINT IF EXISTS engage_token_grants_cmp_chk');
        $db->query('ALTER TABLE engage_token_grants ADD CONSTRAINT engage_token_grants_cmp_chk CHECK (cmp_id IS NULL OR cmp_id > 0)');
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query('ALTER TABLE engage_token_grants DROP CONSTRAINT IF EXISTS engage_token_grants_cmp_chk');
        $db->query('ALTER TABLE engage_token_grants DROP COLUMN IF EXISTS cmp_id');
        $db->query('DROP INDEX IF EXISTS uq_engage_token_orders_open');
        // Back to one open order per holder: the older open orders of a holder are closed first, or the index
        // could not be built.
        $db->query("UPDATE engage_token_orders o SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = 'Closed when credit scopes were removed'
            WHERE status IN ('quoted','awaiting_payment') AND EXISTS (SELECT 1 FROM engage_token_orders n
                WHERE n.holder_uuid = o.holder_uuid AND n.status IN ('quoted','awaiting_payment') AND n.id > o.id)");
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_orders_open ON engage_token_orders (holder_uuid) WHERE status IN ('quoted','awaiting_payment')");
        $db->query('ALTER TABLE engage_token_orders DROP CONSTRAINT IF EXISTS engage_token_orders_scope_chk');
        foreach (['company_name', 'credit_cmp_id', 'credit_scope'] as $c) {
            $db->query("ALTER TABLE engage_token_orders DROP COLUMN IF EXISTS {$c}");
        }
    }
}

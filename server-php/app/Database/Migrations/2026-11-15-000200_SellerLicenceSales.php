<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sellers buy licences and apply them to their customers' accounts (docs/SELLING_LICENCES.md §7). Additive.
 *
 *   engage_seller_licence_orders       one purchase by a seller (SLO-NNNNNN): totals frozen when it is placed, the
 *                                      buyer as invoiced (the seller's KYC legal name, GSTIN, state), how it is paid
 *                                      (a Finance payment link, or offline with staff confirming), and the payment
 *                                      that settled it. awaiting_payment → paid | cancelled | expired.
 *   engage_seller_licence_order_lines  per offer: the exact version and snapshot bought, quantity, unit price on the
 *                                      offer's basis, and the line's GST as Finance bills it.
 *   engage_seller_licence_units        the stock: one row per licence bought (SLU-NNNNNN), with its exact share of the
 *                                      line's taxable value and GST (shares sum to the line). available → applied
 *                                      (to a customer: new licence or renewal) | void (staff, e.g. refunded).
 *   engage_licences                    + seller_id (sold through this seller); source gains `seller` — such a licence
 *                                      has no Engage account (like a provisioned Basic) and its order_ref is the unit.
 *   engage_licence_events              actor_kind gains `seller` (shown by the seller's name in Manage's activity).
 *   engage_lead_payments               + seller_id (the seller who made this sale: commission goes to them, not
 *                                      necessarily the lead's seller), + commission_sequence (the payment number the
 *                                      plan rules see — per product for licence sales, so every new customer licence is
 *                                      a "first payment"), + source (staff | seller_licence), + licence_unit_id.
 *   engage_settings                    seller_order_validity_days (7), seller_order_payment_instructions ('').
 */
class SellerLicenceSales extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_seller_licence_order_ref_seq START 1');
        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_seller_licence_unit_ref_seq START 1');

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_licence_orders (
            id                 BIGSERIAL PRIMARY KEY,
            order_ref          VARCHAR(24)  NOT NULL UNIQUE,
            seller_id          BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT,
            status             VARCHAR(20)  NOT NULL DEFAULT 'awaiting_payment',
            currency           VARCHAR(3)   NOT NULL DEFAULT 'INR',
            list_paise         BIGINT       NOT NULL,
            taxable_paise      BIGINT       NOT NULL,
            gst_paise          BIGINT       NOT NULL,
            total_paise        BIGINT       NOT NULL,
            unit_count         INTEGER      NOT NULL,
            buyer_legal_name   VARCHAR(191) NOT NULL,
            buyer_gstin        VARCHAR(15)  NULL,
            buyer_state_code   VARCHAR(2)   NOT NULL,
            buyer_address      TEXT         NULL,
            buyer_pincode      VARCHAR(6)   NULL,
            buyer_email        VARCHAR(191) NOT NULL,
            buyer_mobile       VARCHAR(32)  NULL,
            payment_mode       VARCHAR(12)  NOT NULL DEFAULT 'offline',
            link_id            VARCHAR(96)  NULL,
            link_url           TEXT         NULL,
            link_expires_at    TIMESTAMPTZ  NULL,
            link_error         TEXT         NULL,
            payment_ref        VARCHAR(96)  NULL,
            amount_paid_paise  BIGINT       NULL,
            invoice_no         VARCHAR(64)  NULL,
            invoice_date       DATE         NULL,
            paid_at            TIMESTAMPTZ  NULL,
            settled_via        VARCHAR(12)  NULL,
            settled_by         BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            expires_at         TIMESTAMPTZ  NOT NULL,
            cancelled_at       TIMESTAMPTZ  NULL,
            cancel_reason      TEXT         NULL,
            cancelled_by_kind  VARCHAR(8)   NULL,
            idempotency_key    VARCHAR(128) NULL,
            request_hash       VARCHAR(64)  NULL,
            created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_slo_status_chk  CHECK (status IN ('awaiting_payment','paid','cancelled','expired')),
            CONSTRAINT engage_slo_mode_chk    CHECK (payment_mode IN ('link','offline')),
            CONSTRAINT engage_slo_amounts_chk CHECK (total_paise >= 100 AND taxable_paise >= 0 AND gst_paise >= 0 AND total_paise = taxable_paise + gst_paise AND unit_count >= 1),
            CONSTRAINT engage_slo_paid_chk    CHECK (status <> 'paid' OR (payment_ref IS NOT NULL AND paid_at IS NOT NULL AND settled_via IN ('finance','staff'))),
            CONSTRAINT engage_slo_cancel_chk  CHECK (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)),
            CONSTRAINT engage_slo_state_chk   CHECK (buyer_state_code ~ '^[0-9]{2}$')
        )");
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_slo_idem ON engage_seller_licence_orders (seller_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_slo_payment ON engage_seller_licence_orders (payment_ref) WHERE payment_ref IS NOT NULL');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_slo_seller ON engage_seller_licence_orders (seller_id, created_at DESC, id DESC)');
        $db->query("CREATE INDEX IF NOT EXISTS idx_engage_slo_due ON engage_seller_licence_orders (expires_at) WHERE status = 'awaiting_payment'");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_licence_order_lines (
            id                 BIGSERIAL PRIMARY KEY,
            order_id           BIGINT       NOT NULL REFERENCES engage_seller_licence_orders(id) ON DELETE RESTRICT,
            line_no            SMALLINT     NOT NULL,
            offer_id           BIGINT       NOT NULL REFERENCES engage_offers(id) ON DELETE RESTRICT,
            offer_code         VARCHAR(64)  NOT NULL,
            offer_version      INTEGER      NOT NULL,
            offer_snapshot     JSONB        NOT NULL,
            product_code       VARCHAR(64)  NOT NULL,
            label              VARCHAR(191) NOT NULL,
            quantity           INTEGER      NOT NULL,
            unit_price_paise   BIGINT       NOT NULL,
            price_basis        VARCHAR(10)  NOT NULL,
            gst_rate           NUMERIC(5,2) NOT NULL,
            sac                VARCHAR(8)   NOT NULL,
            line_amount_paise  BIGINT       NOT NULL,
            taxable_paise      BIGINT       NOT NULL,
            gst_paise          BIGINT       NOT NULL,
            total_paise        BIGINT       NOT NULL,
            UNIQUE (order_id, line_no),
            CONSTRAINT engage_slol_qty_chk    CHECK (quantity BETWEEN 1 AND 100),
            CONSTRAINT engage_slol_basis_chk  CHECK (price_basis IN ('inclusive','exclusive')),
            CONSTRAINT engage_slol_amount_chk CHECK (unit_price_paise > 0 AND line_amount_paise = unit_price_paise * quantity AND total_paise = taxable_paise + gst_paise)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS engage_seller_licence_units (
            id                 BIGSERIAL PRIMARY KEY,
            unit_ref           VARCHAR(24)  NOT NULL UNIQUE,
            order_id           BIGINT       NOT NULL REFERENCES engage_seller_licence_orders(id) ON DELETE RESTRICT,
            line_id            BIGINT       NOT NULL REFERENCES engage_seller_licence_order_lines(id) ON DELETE RESTRICT,
            seller_id          BIGINT       NOT NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT,
            offer_id           BIGINT       NOT NULL REFERENCES engage_offers(id) ON DELETE RESTRICT,
            offer_code         VARCHAR(64)  NOT NULL,
            offer_version      INTEGER      NOT NULL,
            product_code       VARCHAR(64)  NOT NULL,
            taxable_paise      BIGINT       NOT NULL,
            gst_paise          BIGINT       NOT NULL,
            total_paise        BIGINT       NOT NULL,
            status             VARCHAR(12)  NOT NULL DEFAULT 'available',
            applied_at         TIMESTAMPTZ  NULL,
            apply_kind         VARCHAR(8)   NULL,
            licence_ref        VARCHAR(32)  NULL,
            holder_uuid        VARCHAR(64)  NULL,
            customer_email     VARCHAR(255) NULL,
            lead_id            BIGINT       NULL REFERENCES engage_leads(id) ON DELETE SET NULL,
            payment_id         BIGINT       NULL REFERENCES engage_lead_payments(id) ON DELETE SET NULL,
            commission_note    VARCHAR(255) NULL,
            idempotency_key    VARCHAR(128) NULL,
            request_hash       VARCHAR(64)  NULL,
            voided_at          TIMESTAMPTZ  NULL,
            voided_by          BIGINT       NULL REFERENCES engage_users(id) ON DELETE SET NULL,
            void_reason        TEXT         NULL,
            created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_slu_status_chk  CHECK (status IN ('available','applied','void')),
            CONSTRAINT engage_slu_kind_chk    CHECK (apply_kind IS NULL OR apply_kind IN ('new','renewal')),
            CONSTRAINT engage_slu_applied_chk CHECK (status <> 'applied' OR (applied_at IS NOT NULL AND apply_kind IS NOT NULL AND licence_ref IS NOT NULL AND holder_uuid IS NOT NULL)),
            CONSTRAINT engage_slu_void_chk    CHECK (status <> 'void' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL)),
            CONSTRAINT engage_slu_amount_chk  CHECK (taxable_paise >= 0 AND gst_paise >= 0 AND total_paise = taxable_paise + gst_paise)
        )");
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_slu_idem ON engage_seller_licence_units (seller_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_slu_seller ON engage_seller_licence_units (seller_id, status, offer_code)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_slu_order ON engage_seller_licence_units (order_id)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_slu_licence ON engage_seller_licence_units (licence_ref) WHERE licence_ref IS NOT NULL');

        // Licences sold through a seller.
        $db->query('ALTER TABLE engage_licences ADD COLUMN IF NOT EXISTS seller_id BIGINT NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT');
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_source_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_source_chk CHECK (source IN ('staff','order','provisioning','seller'))");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_account_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_account_chk CHECK (account_id IS NOT NULL OR source IN ('provisioning','seller'))");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_seller_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_seller_chk CHECK (source <> 'seller' OR (seller_id IS NOT NULL AND order_ref IS NOT NULL AND holder_uuid IS NOT NULL))");
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_licences_seller ON engage_licences (seller_id, created_at DESC) WHERE seller_id IS NOT NULL');

        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_actor_chk');
        $db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_actor_chk CHECK (actor_kind IN ('staff','system','order','seller'))");

        // The sale a commission is earned on.
        $db->query('ALTER TABLE engage_lead_payments ADD COLUMN IF NOT EXISTS seller_id BIGINT NULL REFERENCES engage_sellers(id) ON DELETE RESTRICT');
        $db->query('ALTER TABLE engage_lead_payments ADD COLUMN IF NOT EXISTS commission_sequence INTEGER NULL');
        $db->query("ALTER TABLE engage_lead_payments ADD COLUMN IF NOT EXISTS source VARCHAR(16) NOT NULL DEFAULT 'staff'");
        $db->query('ALTER TABLE engage_lead_payments ADD COLUMN IF NOT EXISTS licence_unit_id BIGINT NULL REFERENCES engage_seller_licence_units(id) ON DELETE RESTRICT');
        $db->query('ALTER TABLE engage_lead_payments DROP CONSTRAINT IF EXISTS engage_lead_payments_source_chk');
        $db->query("ALTER TABLE engage_lead_payments ADD CONSTRAINT engage_lead_payments_source_chk CHECK (source IN ('staff','seller_licence')
            AND (source <> 'seller_licence' OR (licence_unit_id IS NOT NULL AND seller_id IS NOT NULL))
            AND (commission_sequence IS NULL OR commission_sequence >= 1))");
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_lead_payments_unit ON engage_lead_payments (licence_unit_id) WHERE licence_unit_id IS NOT NULL');

        foreach ([
            ['seller_order_validity_days', 7, 'Days a seller\'s licence order stays open for payment before it expires (its price is held meanwhile).'],
            ['seller_order_payment_instructions', '', 'How a seller pays a licence order while payment links are off: bank account, IFSC, UPI — shown on every unpaid order with its reference.'],
        ] as [$key, $value, $desc]) {
            $db->query('INSERT INTO engage_settings (key, value_json, description, created_at, updated_at)
                        VALUES (?, CAST(? AS JSONB), ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (key) DO NOTHING',
                [$key, json_encode($value), $desc]);
        }
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query("DELETE FROM engage_settings WHERE key IN ('seller_order_validity_days','seller_order_payment_instructions')");
        $db->query('DROP INDEX IF EXISTS uq_engage_lead_payments_unit');
        $db->query('ALTER TABLE engage_lead_payments DROP CONSTRAINT IF EXISTS engage_lead_payments_source_chk');
        foreach (['licence_unit_id', 'source', 'commission_sequence', 'seller_id'] as $c) {
            $db->query("ALTER TABLE engage_lead_payments DROP COLUMN IF EXISTS {$c}");
        }
        $db->query('ALTER TABLE engage_licence_events DROP CONSTRAINT IF EXISTS engage_licence_events_actor_chk');
        // NOT VALID: rows a seller wrote stay (events are append-only); only new rows are held to the old list.
        $db->query("ALTER TABLE engage_licence_events ADD CONSTRAINT engage_licence_events_actor_chk CHECK (actor_kind IN ('staff','system','order')) NOT VALID");
        $db->query('DROP INDEX IF EXISTS idx_engage_licences_seller');
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_seller_chk');
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_account_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_account_chk CHECK (account_id IS NOT NULL OR source = 'provisioning') NOT VALID");
        $db->query('ALTER TABLE engage_licences DROP CONSTRAINT IF EXISTS engage_licences_source_chk');
        $db->query("ALTER TABLE engage_licences ADD CONSTRAINT engage_licences_source_chk CHECK (source IN ('staff','order','provisioning')) NOT VALID");
        $db->query('ALTER TABLE engage_licences DROP COLUMN IF EXISTS seller_id');
        $db->query('DROP TABLE IF EXISTS engage_seller_licence_units');
        $db->query('DROP TABLE IF EXISTS engage_seller_licence_order_lines');
        $db->query('DROP TABLE IF EXISTS engage_seller_licence_orders');
        $db->query('DROP SEQUENCE IF EXISTS engage_seller_licence_unit_ref_seq');
        $db->query('DROP SEQUENCE IF EXISTS engage_seller_licence_order_ref_seq');
    }
}

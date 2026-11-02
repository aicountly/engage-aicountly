<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Buying AI tokens (docs/TOKEN_CONVERSIONS.md §12).
 *
 * One row per order, from "the customer asked" to "the tokens are theirs":
 *
 *   quoted ──link requested──▶ awaiting_payment ──Finance confirms──▶ paid ──granted──▶ granted
 *      │                              │
 *      └──────── cancelled ◀──────────┴──── expired
 *
 * WHY AN ORDER AT ALL, rather than just a Finance payment link. Three facts have
 * to survive the round trip to the gateway and back, and none of them belongs to
 * Finance: how many tokens were bought, at what rate they were priced, and which
 * coupon was applied. A link knows an amount; it does not know that the amount was
 * 1,000,000 tokens at 100 paise per thousand less a staff discount. The order is
 * where that is written down before the customer is sent to pay, so what is granted
 * afterwards is what was agreed, not what somebody recomputes later at a rate that
 * may since have changed.
 *
 * THE PRICE IS FROZEN AT QUOTE. token_count, price_paise_per_1000 and every money
 * column are fixed when the order is created. A customer who opens a payment link
 * on Tuesday and pays on Friday gets Tuesday's price, which is the only defensible
 * answer and also the one the invoice already states.
 *
 * STATUS IS NOT A PROGRESS BAR — it is what stops a customer being charged twice or
 * granted twice. `paid` is written only from Finance's confirmed payment, and
 * `granted` only once the grant row exists, in the same transaction, so a crash
 * between the two leaves an order that can be retried rather than tokens that were
 * silently never given.
 *
 * Nothing is seeded.
 */
class CreateTokenOrders extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        $db->query('CREATE SEQUENCE IF NOT EXISTS engage_token_order_ref_seq START 1');
        $db->query("CREATE TABLE IF NOT EXISTS engage_token_orders (
            id                   BIGSERIAL PRIMARY KEY,
            order_ref            VARCHAR(24)  NOT NULL UNIQUE,
            holder_uuid          VARCHAR(64)  NOT NULL,
            cmp_id               BIGINT       NOT NULL,
            -- What was bought, and the rate it was bought at. Frozen at quote.
            tokens               BIGINT       NOT NULL,
            price_paise_per_1000 INTEGER      NOT NULL,
            -- list - discount = net; GST on the net; total is what the customer pays.
            list_paise           BIGINT       NOT NULL,
            discount_paise       BIGINT       NOT NULL DEFAULT 0,
            net_paise            BIGINT       NOT NULL,
            gst_rate             NUMERIC(5,2) NOT NULL,
            gst_paise            BIGINT       NOT NULL,
            total_paise          BIGINT       NOT NULL,
            sac                  VARCHAR(8)   NOT NULL,
            coupon_code          VARCHAR(32)  NULL,
            coupon_id            BIGINT       NULL,
            -- Who is paying, as Finance needs them. A snapshot: the customer may edit
            -- their profile later and the invoice must still say what it said.
            payer_name           VARCHAR(120) NOT NULL,
            payer_email          VARCHAR(254) NULL,
            payer_mobile         VARCHAR(20)  NULL,
            payer_state_code     VARCHAR(2)   NOT NULL,
            payer_address        TEXT         NULL,
            payer_pincode        VARCHAR(6)   NULL,
            buyer_gstin          VARCHAR(15)  NULL,
            status               VARCHAR(20)  NOT NULL DEFAULT 'quoted',
            -- Finance's side of it.
            link_id              VARCHAR(64)  NULL,
            link_url             TEXT         NULL,
            link_expires_at      TIMESTAMPTZ  NULL,
            payment_ref          VARCHAR(96)  NULL,
            invoice_no           VARCHAR(32)  NULL,
            invoice_date         DATE         NULL,
            paid_at              TIMESTAMPTZ  NULL,
            -- The grant this order became, once it became one.
            grant_ref            VARCHAR(96)  NULL,
            granted_at           TIMESTAMPTZ  NULL,
            cancelled_at         TIMESTAMPTZ  NULL,
            cancel_reason        VARCHAR(255) NULL,
            source               VARCHAR(16)  NOT NULL DEFAULT 'manage',
            created_by           BIGINT       NULL,
            created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
            CONSTRAINT engage_token_orders_status_chk
                CHECK (status IN ('quoted','awaiting_payment','paid','granted','cancelled','expired','failed')),
            CONSTRAINT engage_token_orders_tokens_chk CHECK (tokens > 0 AND price_paise_per_1000 > 0),
            CONSTRAINT engage_token_orders_money_chk CHECK (
                list_paise >= 0 AND discount_paise >= 0 AND discount_paise <= list_paise
                AND net_paise = list_paise - discount_paise
                AND gst_paise >= 0 AND total_paise = net_paise + gst_paise
                -- A gateway cannot collect less than a rupee, so a fully discounted
                -- order is not an order: that is a voucher, and vouchers are free
                -- supplies with no invoice (engage_token_vouchers).
                AND total_paise >= 100
            ),
            CONSTRAINT engage_token_orders_paid_chk   CHECK (status <> 'paid'    OR payment_ref IS NOT NULL),
            CONSTRAINT engage_token_orders_grant_chk  CHECK (status <> 'granted' OR grant_ref   IS NOT NULL)
        )");

        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_token_orders_holder ON engage_token_orders (holder_uuid, created_at DESC)');
        $db->query('CREATE INDEX IF NOT EXISTS idx_engage_token_orders_status ON engage_token_orders (status)');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_orders_link ON engage_token_orders (link_id) WHERE link_id IS NOT NULL');
        // One payment settles one order. Finance's payment reference is globally unique,
        // so this is what stops a replayed payment event granting tokens twice.
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_orders_payment ON engage_token_orders (payment_ref) WHERE payment_ref IS NOT NULL');
        // A customer may have only one order waiting to be paid at a time: two open
        // checkouts is how somebody pays twice for one intention.
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_engage_token_orders_open
            ON engage_token_orders (holder_uuid) WHERE status IN ('quoted','awaiting_payment')");

        // Once an order has been paid its commercial facts are evidence: what was
        // bought, at what price, with which coupon. Only the lifecycle may move.
        $db->query("CREATE OR REPLACE FUNCTION engage_token_orders_guard() RETURNS TRIGGER AS \$\$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF OLD.status <> 'quoted' THEN
                    RAISE EXCEPTION 'a token order that has been offered for payment is never deleted: cancel it';
                END IF;
                RETURN OLD;
            END IF;
            IF OLD.status IN ('paid','granted') AND (
                NEW.tokens, NEW.price_paise_per_1000, NEW.list_paise, NEW.discount_paise,
                NEW.net_paise, NEW.gst_paise, NEW.total_paise, NEW.coupon_code, NEW.holder_uuid, NEW.cmp_id
            ) IS DISTINCT FROM (
                OLD.tokens, OLD.price_paise_per_1000, OLD.list_paise, OLD.discount_paise,
                OLD.net_paise, OLD.gst_paise, OLD.total_paise, OLD.coupon_code, OLD.holder_uuid, OLD.cmp_id
            ) THEN
                RAISE EXCEPTION 'a paid token order cannot be re-priced';
            END IF;
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql");
        $db->query('DROP TRIGGER IF EXISTS trg_engage_token_orders_guard ON engage_token_orders');
        $db->query('CREATE TRIGGER trg_engage_token_orders_guard
            BEFORE UPDATE OR DELETE ON engage_token_orders
            FOR EACH ROW EXECUTE PROCEDURE engage_token_orders_guard()');
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS trg_engage_token_orders_guard ON engage_token_orders');
        $this->db->query('DROP FUNCTION IF EXISTS engage_token_orders_guard()');
        $this->db->query('DROP TABLE IF EXISTS engage_token_orders');
        $this->db->query('DROP SEQUENCE IF EXISTS engage_token_order_ref_seq');
    }
}

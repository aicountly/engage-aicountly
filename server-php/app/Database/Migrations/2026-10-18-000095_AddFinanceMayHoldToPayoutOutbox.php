<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * engage_finance_payout_outbox.finance_may_hold — sticky "Finance may already hold this batch".
 *
 * Set by FinancePayoutOutbox on the first delivery attempt whose answer does not prove Finance stored nothing:
 * a receipt, a 409 (Finance knows the batch_ref), a 5xx / timeout / other unknown answer (the 201 may have been
 * lost). Never cleared (a re-send keeps it). Engage's "Cancel batch" is refused while it is true — such a batch must
 * be cancelled in Finance, whose `failed` callbacks return the entries to approved.
 *
 * Existing rows: true unless nothing was ever attempted or the only attempt was an explicit 400 / 422 rejection
 * (what earlier attempts saw is not recorded, so assume Finance may hold it; a re-send settles it).
 */
class AddFinanceMayHoldToPayoutOutbox extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE engage_finance_payout_outbox ADD COLUMN IF NOT EXISTS finance_may_hold BOOLEAN NOT NULL DEFAULT FALSE');
        $this->db->query("UPDATE engage_finance_payout_outbox SET finance_may_hold = TRUE
                           WHERE status = 'delivered'
                              OR (attempts > 0 AND NOT (status = 'rejected' AND last_http_status IN (400, 422) AND attempts = 1))");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE engage_finance_payout_outbox DROP COLUMN IF EXISTS finance_may_hold');
    }
}

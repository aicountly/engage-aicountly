<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\CommissionRules;
use App\Libraries\Seller\Money;
use App\Models\LeadActivitiesModel;
use App\Models\LeadsModel;
use App\Models\SettingsModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Commission plans, confirmed customer payments and commission entries (bookkeeping only).
 *
 * Plans: draft → active → retired, versioned per plan_code (editing an active plan = a new draft version;
 * publishing it retires the previous version). Active plans of different codes must not overlap in
 * product scope, so exactly one plan can apply to a payment. Engage ships with no plan: nothing accrues
 * until an admin publishes one.
 *
 * Accrual (contract: lead converted + payment confirmed in Engage): each confirmed payment is evaluated
 * once — when it is confirmed on a converted lead, or when its lead converts — against the plan active at
 * that moment for the payment's product. The lead must be attributed to a seller who is not terminated.
 * Entry: accrued, hold_until = now + plan.hold_days. `php spark engage:commission-release` (hourly)
 * moves due entries to approved. Reversal (staff, or a refunded payment) applies to accrued / approved
 * entries. in_payout entries belong to a weekly payout with Finance (Services/Payouts); paid ones would need a
 * clawback, which Engage does not do.
 */
class CommissionService
{
    private ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db;
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    // ------------------------------------------------------------------ plans

    /** @param array<string,mixed> $p */
    public static function planView(array $p): array
    {
        $rules = is_array($p['rules']) ? $p['rules'] : (json_decode((string) $p['rules'], true) ?: []);
        $scope = is_array($p['product_scope']) ? $p['product_scope'] : (json_decode((string) $p['product_scope'], true) ?: ['*']);

        return [
            'id'            => (int) $p['id'],
            'plan_code'     => $p['plan_code'],
            'version'       => (int) $p['version'],
            'name'          => $p['name'],
            'description'   => $p['description'],
            'status'        => $p['status'],
            'rules'         => $rules,
            'product_scope' => $scope,
            'hold_days'     => (int) $p['hold_days'],
            'published_at'  => Clock::iso($p['published_at'] ?? null),
            'published_by'  => $p['published_by'] !== null ? (int) $p['published_by'] : null,
            'retired_at'    => Clock::iso($p['retired_at'] ?? null),
            'created_at'    => Clock::iso($p['created_at'] ?? null),
            'updated_at'    => Clock::iso($p['updated_at'] ?? null),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listPlans(?string $status = null): array
    {
        $b = $this->db()->table('engage_commission_plans');
        if ($status !== null && $status !== '') {
            $b->where('status', $status);
        }

        return array_map([self::class, 'planView'], $b->orderBy('plan_code')->orderBy('version', 'DESC')->get()->getResultArray());
    }

    /** @return array<string,mixed>|null */
    public function findPlan(int $id): ?array
    {
        return $this->db()->table('engage_commission_plans')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function activePlans(): array
    {
        return array_map([self::class, 'planView'], $this->db()->table('engage_commission_plans')->where('status', 'active')->get()->getResultArray());
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function planFields(array $in, bool $creating): array
    {
        $e = [];
        $out = [];
        if ($creating) {
            $code = strtolower(trim((string) ($in['plan_code'] ?? '')));
            if (! preg_match('/\A[a-z0-9_\-]{2,48}\z/', $code)) {
                $e['plan_code'] = 'plan_code: 2–48 lowercase letters, digits, - or _.';
            }
            $out['plan_code'] = $code;
        }
        if ($creating || array_key_exists('name', $in)) {
            $name = trim((string) ($in['name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
                $e['name'] = 'Give the plan a name.';
            }
            $out['name'] = $name;
        }
        if (array_key_exists('description', $in)) {
            $out['description'] = trim((string) $in['description']) ?: null;
        }
        if ($creating || array_key_exists('rules', $in)) {
            $rules = $in['rules'] ?? null;
            if (is_string($rules)) {
                $rules = json_decode($rules, true);
            }
            [$norm, $re] = CommissionRules::normalise($rules);
            $e += $re;
            $out['rules'] = $norm;
        }
        if ($creating || array_key_exists('product_scope', $in)) {
            [$scope, $se] = CommissionRules::normaliseScope($in['product_scope'] ?? null);
            if ($se !== null) {
                $e['product_scope'] = $se;
            }
            $out['product_scope'] = $scope;
        }
        if ($creating || array_key_exists('hold_days', $in)) {
            $hd = $in['hold_days'] ?? (new SettingsModel())->getSetting('commission_default_hold_days', 15);
            if (! is_numeric($hd) || (int) $hd != $hd || (int) $hd < 0 || (int) $hd > 365) {
                $e['hold_days'] = 'hold_days must be a whole number from 0 to 365.';
            }
            $out['hold_days'] = (int) $hd;
        }

        return [$out, $e];
    }

    /** @return array<string,mixed> */
    public function createDraft(array $in, array $staff): array
    {
        [$f, $e] = $this->planFields($in, true);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the plan.', 'details' => $e];
        }
        $max = (int) ($this->db()->query('SELECT MAX(version) AS v FROM engage_commission_plans WHERE plan_code = ?', [$f['plan_code']])->getRow()->v ?? 0);
        if ($max > 0) {
            return ['status' => 409, 'error' => "Plan code {$f['plan_code']} already exists — create a new version of it instead."];
        }
        $this->db()->table('engage_commission_plans')->insert([
            'plan_code' => $f['plan_code'], 'version' => 1, 'name' => $f['name'], 'description' => $f['description'] ?? null,
            'status' => 'draft', 'rules' => json_encode($f['rules']), 'product_scope' => json_encode($f['product_scope']),
            'hold_days' => $f['hold_days'], 'created_by' => (int) $staff['id'],
        ]);
        $id = (int) $this->db()->insertID();
        $this->audit('commission.plan_created', $id, ['plan_code' => $f['plan_code'], 'version' => 1]);

        return ['status' => 201, 'data' => self::planView($this->findPlan($id))];
    }

    /** @return array<string,mixed> */
    public function updateDraft(int $id, array $in, array $staff): array
    {
        $p = $this->findPlan($id);
        if (! $p) {
            return ['status' => 404, 'error' => 'Plan not found.'];
        }
        if ($p['status'] !== 'draft') {
            return ['status' => 409, 'error' => 'Only a draft can be edited. Create a new version of an active or retired plan.'];
        }
        [$f, $e] = $this->planFields($in, false);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the plan.', 'details' => $e];
        }
        if (isset($f['rules'])) {
            $f['rules'] = json_encode($f['rules']);
        }
        if (isset($f['product_scope'])) {
            $f['product_scope'] = json_encode($f['product_scope']);
        }
        if ($f !== []) {
            $f['updated_at'] = Clock::now();
            $this->db()->table('engage_commission_plans')->where('id', $id)->update($f);
        }
        $this->audit('commission.plan_updated', $id, ['plan_code' => $p['plan_code'], 'version' => (int) $p['version']]);

        return ['status' => 200, 'data' => self::planView($this->findPlan($id))];
    }

    /** @return array<string,mixed> */
    public function newVersion(int $id, array $staff): array
    {
        $p = $this->findPlan($id);
        if (! $p) {
            return ['status' => 404, 'error' => 'Plan not found.'];
        }
        $draft = $this->db()->query("SELECT id FROM engage_commission_plans WHERE plan_code = ? AND status = 'draft' LIMIT 1", [$p['plan_code']])->getRow();
        if ($draft) {
            return ['status' => 409, 'error' => 'This plan already has a draft version (#' . $draft->id . '). Edit that one.'];
        }
        $max = (int) $this->db()->query('SELECT MAX(version) AS v FROM engage_commission_plans WHERE plan_code = ?', [$p['plan_code']])->getRow()->v;
        $this->db()->table('engage_commission_plans')->insert([
            'plan_code' => $p['plan_code'], 'version' => $max + 1, 'name' => $p['name'], 'description' => $p['description'],
            'status' => 'draft', 'rules' => $p['rules'], 'product_scope' => $p['product_scope'], 'hold_days' => $p['hold_days'],
            'created_by' => (int) $staff['id'],
        ]);
        $nid = (int) $this->db()->insertID();
        $this->audit('commission.plan_version_created', $nid, ['plan_code' => $p['plan_code'], 'version' => $max + 1, 'from_plan_id' => $id]);

        return ['status' => 201, 'data' => self::planView($this->findPlan($nid))];
    }

    /** @return array<string,mixed> */
    public function publish(int $id, array $staff): array
    {
        $p = $this->findPlan($id);
        if (! $p) {
            return ['status' => 404, 'error' => 'Plan not found.'];
        }
        if ($p['status'] !== 'draft') {
            return ['status' => 409, 'error' => 'Only a draft can be published.'];
        }
        $view = self::planView($p);
        [, $re] = CommissionRules::normalise($view['rules']);
        if ($re !== []) {
            return ['status' => 422, 'error' => 'The plan rules are not valid.', 'details' => $re];
        }
        foreach ($this->activePlans() as $a) {
            if ($a['plan_code'] !== $p['plan_code'] && CommissionRules::scopesOverlap($a['product_scope'], $view['product_scope'])) {
                return ['status' => 409, 'error' => "Active plan {$a['plan_code']} v{$a['version']} already covers some of these products. Retire it or narrow the product scope first."];
            }
        }
        $db = $this->db();
        $db->transStart();
        $previous = $db->query("SELECT id, version FROM engage_commission_plans WHERE plan_code = ? AND status = 'active'", [$p['plan_code']])->getRowArray();
        if ($previous) {
            $db->table('engage_commission_plans')->where('id', (int) $previous['id'])->update([
                'status' => 'retired', 'retired_at' => Clock::now(), 'retired_by' => (int) $staff['id'], 'updated_at' => Clock::now(),
            ]);
        }
        $db->table('engage_commission_plans')->where('id', $id)->update([
            'status' => 'active', 'published_at' => Clock::now(), 'published_by' => (int) $staff['id'], 'updated_at' => Clock::now(),
        ]);
        $db->transComplete();
        if (! $db->transStatus()) {
            return ['status' => 500, 'error' => 'Could not publish the plan.'];
        }
        $this->audit('commission.plan_published', $id, ['plan_code' => $p['plan_code'], 'version' => (int) $p['version'], 'retired_plan_id' => $previous['id'] ?? null]);

        return ['status' => 200, 'data' => self::planView($this->findPlan($id))];
    }

    /** @return array<string,mixed> */
    public function retire(int $id, array $staff): array
    {
        $p = $this->findPlan($id);
        if (! $p) {
            return ['status' => 404, 'error' => 'Plan not found.'];
        }
        if (! in_array($p['status'], ['active', 'draft'], true)) {
            return ['status' => 409, 'error' => 'This plan is already retired.'];
        }
        $this->db()->table('engage_commission_plans')->where('id', $id)->update([
            'status' => 'retired', 'retired_at' => Clock::now(), 'retired_by' => (int) $staff['id'], 'updated_at' => Clock::now(),
        ]);
        $this->audit('commission.plan_retired', $id, ['plan_code' => $p['plan_code'], 'version' => (int) $p['version'], 'was' => $p['status']]);

        return ['status' => 200, 'data' => self::planView($this->findPlan($id))];
    }

    /** The active plan covering a product, or null. @return array<string,mixed>|null */
    public function planFor(?string $productCode): ?array
    {
        foreach ($this->activePlans() as $p) {
            if (CommissionRules::scopeCovers($p['product_scope'], $productCode)) {
                return $p;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ payments

    /** @param array<string,mixed> $p */
    public static function paymentView(array $p): array
    {
        return [
            'id'           => (int) $p['id'],
            'lead_id'      => (int) $p['lead_id'],
            'payment_ref'  => $p['payment_ref'],
            'sequence_no'  => (int) $p['sequence_no'],
            'amount'       => Money::format(Money::toPaise((string) $p['amount'])),
            'currency'     => $p['currency'],
            'product_code' => $p['product_code'],
            'paid_at'      => Clock::iso($p['paid_at']),
            'status'       => $p['status'],
            'notes'        => $p['notes'],
            'confirmed_by' => $p['confirmed_by'] !== null ? (int) $p['confirmed_by'] : null,
            'confirmed_at' => Clock::iso($p['confirmed_at']),
            'refunded_at'  => Clock::iso($p['refunded_at'] ?? null),
            'refund_reason'=> $p['refund_reason'] ?? null,
            'commission_evaluated_at' => Clock::iso($p['commission_evaluated_at'] ?? null),
            'commission_note' => $p['commission_note'] ?? null,
            // Commission basis (D-C1: net invoiced after discount, before GST). basis_kind net_before_gst | payment_amount.
            'quote_ref'      => $p['quote_ref'] ?? null,
            'list_paise'     => isset($p['list_paise']) ? (int) $p['list_paise'] : null,
            'discount_paise' => isset($p['discount_paise']) ? (int) $p['discount_paise'] : null,
            'basis_paise'    => isset($p['basis_paise']) ? (int) $p['basis_paise'] : null,
            'basis_kind'     => $p['basis_kind'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function payments(int $leadId): array
    {
        return array_map([self::class, 'paymentView'],
            $this->db()->table('engage_lead_payments')->where('lead_id', $leadId)->orderBy('sequence_no')->get()->getResultArray());
    }

    /**
     * Staff "payment confirmed" (idempotent on the lead + payment_ref natural key).
     *
     * @return array<string,mixed>
     */
    public function confirmPayment(int $leadId, array $in, array $staff): array
    {
        $leads = new LeadsModel();
        $lead = $leads->find($leadId);
        if (! $lead) {
            return ['status' => 404, 'error' => 'Lead not found.'];
        }
        $e = [];
        try {
            $paise = Money::toPaise($in['amount'] ?? null);
            if ($paise <= 0) {
                $e['amount'] = 'amount must be greater than 0.';
            }
        } catch (\InvalidArgumentException $ex) {
            $e['amount'] = 'amount must be an INR amount like "1234.50".';
            $paise = 0;
        }
        $ref = trim((string) ($in['payment_ref'] ?? ''));
        if (! preg_match('/\A[A-Za-z0-9_.:\/\-]{3,96}\z/', $ref)) {
            $e['payment_ref'] = 'payment_ref (invoice / receipt / gateway id) is required: 3–96 letters, digits or _ . : / -';
        }
        $paidTs = time();
        if (! empty($in['paid_at'])) {
            $t = strtotime((string) $in['paid_at']);
            if ($t === false || $t > time() + 86400) {
                $e['paid_at'] = 'paid_at must be a date that is not in the future.';
            } else {
                $paidTs = $t;
            }
        }
        $product = trim((string) ($in['product_code'] ?? '')) ?: null;
        if (($in['currency'] ?? 'INR') !== 'INR') {
            $e['currency'] = 'Only INR is supported.';
        }
        // Commission basis = the net invoiced after discount and before GST (D-C1, docs/COUPONS.md §8): from the quote
        // (its committed coupon redemption) when quote_ref is given, else the typed net_amount (taxable value), else —
        // as before — the amount paid.
        [$basis, $be] = $this->basisFor($in, $paise);
        $e += $be;
        $product ??= $basis['product_code'] ?? null;
        $product ??= $lead['interested_product'] ?? null;
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the payment.', 'details' => $e];
        }

        $db = $this->db();
        $existing = $db->table('engage_lead_payments')->where('lead_id', $leadId)->where('payment_ref', $ref)->get()->getRowArray();
        if ($existing) {
            if (Money::toPaise((string) $existing['amount']) !== $paise) {
                return ['status' => 409, 'error' => "Payment {$ref} is already recorded for this lead with a different amount."];
            }

            return ['status' => 200, 'data' => ['payment' => self::paymentView($existing), 'duplicate' => true, 'entry' => $this->entryForPayment((int) $existing['id'])]];
        }

        $db->transStart();
        $db->query('SELECT id FROM engage_leads WHERE id = ? FOR UPDATE', [$leadId]);
        $seq = (int) $db->query('SELECT COUNT(*) AS n FROM engage_lead_payments WHERE lead_id = ?', [$leadId])->getRow()->n + 1;
        $db->query(
            'INSERT INTO engage_lead_payments (lead_id, payment_ref, sequence_no, amount, product_code, paid_at, notes, confirmed_by,
                quote_ref, list_paise, discount_paise, basis_paise, basis_kind)
             VALUES (?, ?, ?, CAST(? AS NUMERIC), ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (lead_id, payment_ref) DO NOTHING',
            [$leadId, $ref, $seq, Money::format($paise), $product, Clock::at($paidTs), trim((string) ($in['notes'] ?? '')) ?: null, (int) $staff['id'],
                $basis['quote_ref'], $basis['list_paise'], $basis['discount_paise'], $basis['basis_paise'], $basis['basis_kind']]
        );
        $db->transComplete();
        $payment = $db->table('engage_lead_payments')->where('lead_id', $leadId)->where('payment_ref', $ref)->get()->getRowArray();
        if (! $payment) {
            return ['status' => 500, 'error' => 'Could not record the payment.'];
        }

        (new LeadActivitiesModel())->record($leadId, 'payment_confirmed', 'Payment confirmed: INR ' . Money::format($paise) . " ({$ref})", trim((string) ($in['notes'] ?? '')) ?: null, [
            'metadata' => ['payment_id' => (int) $payment['id'], 'sequence_no' => $seq],
        ]);
        Services::auditService()->log('commission.payment_confirmed', [
            'subject_kind' => 'lead', 'subject_id' => $leadId,
            'metadata' => ['payment_id' => (int) $payment['id'], 'amount' => Money::format($paise), 'payment_ref' => $ref], 'fanout_console' => true,
        ]);
        Services::reachOutcomes()->paymentConfirmed($lead, $payment);
        $accrual = SellerLeadService::isClosedWon($lead) ? $this->accrueForLead($leadId) : ['accrued' => 0, 'notes' => ['Lead is not converted yet; commission is evaluated when it converts.']];

        return ['status' => 201, 'data' => [
            'payment'   => self::paymentView($db->table('engage_lead_payments')->where('id', (int) $payment['id'])->get()->getRowArray()),
            'duplicate' => false,
            'accrual'   => $accrual,
            'entry'     => $this->entryForPayment((int) $payment['id']),
        ]];
    }

    /**
     * Evaluate the lead's not-yet-evaluated confirmed payments for commission.
     *
     * @return array{accrued:int, notes:list<string>}
     */
    public function accrueForLead(int $leadId): array
    {
        $db = $this->db();
        $lead = (new LeadsModel())->find($leadId);
        $notes = [];
        if (! $lead || ! SellerLeadService::isClosedWon($lead)) {
            return ['accrued' => 0, 'notes' => ['Lead is not converted.']];
        }
        $pending = $db->query("SELECT * FROM engage_lead_payments WHERE lead_id = ? AND status = 'confirmed' AND commission_evaluated_at IS NULL ORDER BY sequence_no", [$leadId])->getResultArray();
        if ($pending === []) {
            return ['accrued' => 0, 'notes' => ['No confirmed payment waiting for commission.']];
        }
        $seller = ! empty($lead['seller_id']) ? $db->table('engage_sellers')->where('id', (int) $lead['seller_id'])->get()->getRowArray() : null;
        $accrued = 0;
        foreach ($pending as $pay) {
            $note = null;
            if (! $seller) {
                $note = 'No seller attributed to this lead.';
            } elseif ($seller['status'] === 'terminated') {
                $note = 'Seller ' . $seller['seller_code'] . ' is terminated; no commission.';
            }
            $plan = $note === null ? $this->planFor($pay['product_code'] ?? $lead['interested_product'] ?? null) : null;
            if ($note === null && $plan === null) {
                $note = 'No commission plan published for this product; nothing accrued.';
            }
            $calc = null;
            if ($note === null) {
                $basis = isset($pay['basis_paise']) && $pay['basis_paise'] !== null ? (int) $pay['basis_paise'] : Money::toPaise((string) $pay['amount']);
                $calc = CommissionRules::calculate($plan['rules'], (int) $pay['sequence_no'], $basis);
                if ($calc === null || $calc['amount_paise'] <= 0) {
                    $note = "Plan {$plan['plan_code']} v{$plan['version']} pays nothing on payment #{$pay['sequence_no']}.";
                }
            }
            // Claim the payment for evaluation (once, race-safe).
            $db->query('UPDATE engage_lead_payments SET commission_evaluated_at = NOW(), commission_note = ? WHERE id = ? AND commission_evaluated_at IS NULL',
                [$note !== null ? substr($note, 0, 255) : 'Accrued under ' . $plan['plan_code'] . ' v' . $plan['version'], (int) $pay['id']]);
            if ($db->affectedRows() !== 1) {
                continue;
            }
            if ($note !== null) {
                $notes[] = "Payment {$pay['payment_ref']}: {$note}";

                continue;
            }
            $db->query(
                "INSERT INTO engage_commission_entries (seller_id, lead_id, payment_id, plan_id, plan_code, plan_version, rule_type, product_code,
                        basis_amount, rate_percent, amount, calculation, hold_until, basis_kind)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS NUMERIC), CAST(? AS NUMERIC), CAST(? AS NUMERIC), CAST(? AS JSONB), NOW() + make_interval(days => ?), ?)
                 ON CONFLICT (payment_id) DO NOTHING",
                [(int) $seller['id'], $leadId, (int) $pay['id'], $plan['id'], $plan['plan_code'], $plan['version'], $plan['rules']['type'],
                    $pay['product_code'] ?? $lead['interested_product'], Money::format($basis),
                    $calc['rate_e4'] !== null ? Money::formatPercentE4($calc['rate_e4']) : null, Money::format($calc['amount_paise']),
                    json_encode(['explain' => $calc['explain'], 'payment_sequence' => (int) $pay['sequence_no'], 'rules' => $plan['rules'],
                        'basis_kind' => $pay['basis_kind'] ?? 'payment_amount', 'quote_ref' => $pay['quote_ref'] ?? null]), (int) $plan['hold_days'],
                    $pay['basis_kind'] ?? 'payment_amount']
            );
            if ($db->affectedRows() === 1) {
                $accrued++;
                $notes[] = "Payment {$pay['payment_ref']}: accrued INR " . Money::format($calc['amount_paise']) . " for {$seller['seller_code']} ({$calc['explain']}).";
                (new LeadActivitiesModel())->record($leadId, 'commission_accrued', 'Commission accrued for seller ' . $seller['seller_code'] . ': INR ' . Money::format($calc['amount_paise']), $calc['explain'], [
                    'author_kind' => 'system', 'metadata' => ['payment_id' => (int) $pay['id'], 'plan_id' => $plan['id']],
                ]);
                Services::auditService()->log('commission.accrued', [
                    'subject_kind' => 'lead', 'subject_id' => $leadId,
                    'metadata' => ['seller_code' => $seller['seller_code'], 'payment_id' => (int) $pay['id'], 'amount' => Money::format($calc['amount_paise']),
                        'plan_code' => $plan['plan_code'], 'plan_version' => $plan['version']],
                ]);
            }
        }

        return ['accrued' => $accrued, 'notes' => $notes];
    }

    /**
     * Commission basis of a payment being confirmed (D-C1: net after discount, before GST — never the list price).
     *
     *   quote_ref     → the quote's taxable value (its committed coupon redemption's when it has one); product from the quote
     *   net_amount    → that amount ("1234.50", the invoice taxable value), at most the amount paid
     *   neither       → the amount paid (behaviour before coupons; basis_kind payment_amount)
     *
     * @return array{0: array{quote_ref:?string, list_paise:?int, discount_paise:?int, basis_paise:int, basis_kind:string, product_code:?string}, 1: array<string,string>}
     */
    private function basisFor(array $in, int $amountPaise): array
    {
        $out = ['quote_ref' => null, 'list_paise' => null, 'discount_paise' => null, 'basis_paise' => max(1, $amountPaise), 'basis_kind' => 'payment_amount', 'product_code' => null];
        $quoteRef = strtoupper(trim((string) ($in['quote_ref'] ?? '')));
        if ($quoteRef !== '') {
            $q = $this->db()->table('engage_quotes')->where('quote_ref', $quoteRef)->get()->getRowArray();
            if (! $q) {
                return [$out, ['quote_ref' => 'No quote has this quote_ref.']];
            }
            $r = $this->db()->query("SELECT * FROM engage_coupon_redemptions WHERE quote_ref = ? AND state = 'committed'", [$quoteRef])->getRowArray();
            $src = $r ?: $q;

            return [['quote_ref' => $quoteRef, 'list_paise' => (int) $src['list_paise'], 'discount_paise' => (int) $src['discount_paise'],
                'basis_paise' => max(1, (int) $src['taxable_paise']), 'basis_kind' => 'net_before_gst', 'product_code' => $q['product_code']], []];
        }
        if (isset($in['net_amount']) && $in['net_amount'] !== '' && $in['net_amount'] !== null) {
            try {
                $net = Money::toPaise($in['net_amount']);
            } catch (\InvalidArgumentException) {
                $net = 0;
            }
            if ($net <= 0 || ($amountPaise > 0 && $net > $amountPaise)) {
                return [$out, ['net_amount' => 'The net (taxable) amount after discount, before GST: more than 0 and at most the amount paid.']];
            }

            return [['basis_paise' => $net, 'basis_kind' => 'net_before_gst'] + $out, []];
        }

        return [$out, []];
    }

    /** @return array<string,mixed> */
    public function refundPayment(int $paymentId, array $staff, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Give a reason.']];
        }
        $db = $this->db();
        $p = $db->table('engage_lead_payments')->where('id', $paymentId)->get()->getRowArray();
        if (! $p) {
            return ['status' => 404, 'error' => 'Payment not found.'];
        }
        if ($p['status'] !== 'confirmed') {
            return ['status' => 409, 'error' => 'This payment is already refunded.'];
        }
        $db->table('engage_lead_payments')->where('id', $paymentId)->update([
            'status' => 'refunded', 'refunded_at' => Clock::now(), 'refunded_by' => (int) $staff['id'], 'refund_reason' => $reason,
        ]);
        $reversed = 0;
        $needsClawback = [];
        foreach ($db->table('engage_commission_entries')->where('payment_id', $paymentId)->get()->getResultArray() as $en) {
            if (in_array($en['status'], ['accrued', 'approved'], true)) {
                $this->doReverse((int) $en['id'], (int) $staff['id'], 'Payment refunded: ' . $reason);
                $reversed++;
            } elseif (in_array($en['status'], ['in_payout', 'paid'], true)) {
                $needsClawback[] = (int) $en['id'];
            }
        }
        (new LeadActivitiesModel())->record((int) $p['lead_id'], 'payment_refunded', 'Payment refunded (' . $p['payment_ref'] . ')', $reason);
        Services::auditService()->log('commission.payment_refunded', [
            'subject_kind' => 'lead', 'subject_id' => (int) $p['lead_id'],
            'metadata' => ['payment_id' => $paymentId, 'entries_reversed' => $reversed, 'needs_clawback' => $needsClawback], 'fanout_console' => true,
        ]);

        return ['status' => 200, 'data' => [
            'payment' => self::paymentView($db->table('engage_lead_payments')->where('id', $paymentId)->get()->getRowArray()),
            'entries_reversed' => $reversed,
            'needs_clawback' => $needsClawback,
        ]];
    }

    // ------------------------------------------------------------------ entries

    /** @param array<string,mixed> $e */
    public static function entryView(array $e): array
    {
        return [
            'id'              => (int) $e['id'],
            'seller_id'       => (int) $e['seller_id'],
            'seller_code'     => $e['seller_code'] ?? null,
            'lead_id'         => (int) $e['lead_id'],
            'lead_code'       => $e['lead_code'] ?? null,
            'lead_name'       => $e['lead_name'] ?? null,
            'payment_id'      => (int) $e['payment_id'],
            'payment_ref'     => $e['payment_ref'] ?? null,
            'plan_id'         => (int) $e['plan_id'],
            'plan_code'       => $e['plan_code'],
            'plan_version'    => (int) $e['plan_version'],
            'rule_type'       => $e['rule_type'],
            'product_code'    => $e['product_code'],
            'basis_amount'    => Money::format(Money::toPaise((string) $e['basis_amount'])),
            'basis_kind'      => $e['basis_kind'] ?? null,
            'rate_percent'    => $e['rate_percent'] !== null ? (string) $e['rate_percent'] : null,
            'amount'          => Money::format(Money::toPaise((string) $e['amount'])),
            'currency'        => $e['currency'],
            'status'          => $e['status'],
            'explain'         => (json_decode((string) ($e['calculation'] ?? ''), true) ?: [])['explain'] ?? null,
            'accrued_at'      => Clock::iso($e['accrued_at']),
            'hold_until'      => Clock::iso($e['hold_until']),
            'approved_at'     => Clock::iso($e['approved_at'] ?? null),
            'paid_at'         => Clock::iso($e['paid_at'] ?? null),
            'reversed_at'     => Clock::iso($e['reversed_at'] ?? null),
            'reversal_reason' => $e['reversal_reason'] ?? null,
        ];
    }

    private function entriesQuery()
    {
        return $this->db()->table('engage_commission_entries e')
            ->select('e.*, s.seller_code, l.lead_code, l.name AS lead_name, p.payment_ref')
            ->join('engage_sellers s', 's.id = e.seller_id')
            ->join('engage_leads l', 'l.id = e.lead_id')
            ->join('engage_lead_payments p', 'p.id = e.payment_id');
    }

    /** @return array<string,mixed>|null */
    public function entryForPayment(int $paymentId): ?array
    {
        $r = $this->entriesQuery()->where('e.payment_id', $paymentId)->get()->getRowArray();

        return $r ? self::entryView($r) : null;
    }

    /** @return array<string,mixed> */
    public function listEntries(array $q, int $limit, int $offset): array
    {
        $b = $this->entriesQuery();
        if (! empty($q['seller_id'])) {
            $b->where('e.seller_id', (int) $q['seller_id']);
        }
        if (! empty($q['status'])) {
            $b->where('e.status', (string) $q['status']);
        }
        if (! empty($q['lead_id'])) {
            $b->where('e.lead_id', (int) $q['lead_id']);
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('e.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['items' => array_map([self::class, 'entryView'], $rows), 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function reverse(int $entryId, array $staff, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Give a reason.']];
        }
        $e = $this->db()->table('engage_commission_entries')->where('id', $entryId)->get()->getRowArray();
        if (! $e) {
            return ['status' => 404, 'error' => 'Commission entry not found.'];
        }
        if ($e['status'] === 'in_payout') {
            return ['status' => 409, 'error' => 'This entry is in payout ' . $e['payout_item_ref'] . ' with Finance. It can be reversed once Finance reports that payout failed, reversed or cancelled (the entry then returns to approved).'];
        }
        if ($e['status'] === 'paid') {
            return ['status' => 409, 'error' => 'This entry has been paid out. Recovering paid commission (clawback) is not supported in Engage; handle it with Finance.'];
        }
        if ($e['status'] === 'reversed') {
            return ['status' => 409, 'error' => 'This entry is already reversed.'];
        }
        $this->doReverse($entryId, (int) $staff['id'], $reason);

        return ['status' => 200, 'data' => self::entryView($this->entriesQuery()->where('e.id', $entryId)->get()->getRowArray())];
    }

    private function doReverse(int $entryId, int $staffId, string $reason): void
    {
        $this->db()->query(
            "UPDATE engage_commission_entries SET status = 'reversed', reversed_at = NOW(), reversed_by = ?, reversal_reason = ?, updated_at = NOW()
              WHERE id = ? AND status IN ('accrued','approved')",
            [$staffId, $reason, $entryId]
        );
        Services::auditService()->log('commission.reversed', [
            'subject_kind' => 'commission_entry', 'subject_id' => $entryId, 'metadata' => ['reason' => $reason], 'fanout_console' => true,
        ]);
    }

    /** Hourly: accrued entries whose hold has passed become approved. Returns how many. */
    public function releaseDue(): int
    {
        $rows = $this->db()->query(
            "UPDATE engage_commission_entries SET status = 'approved', approved_at = NOW(), updated_at = NOW()
              WHERE status = 'accrued' AND hold_until <= NOW() RETURNING id"
        )->getResultArray();

        return count($rows);
    }

    // ------------------------------------------------------------------ seller portal

    /** Human description of a plan rule ("10% of first payment"). */
    public static function ruleLabel(array $rules): string
    {
        $pct = static fn (string $p): string => rtrim(rtrim($p, '0'), '.');

        return match ($rules['type'] ?? '') {
            'percent_of_first_payment' => $pct((string) $rules['percent']) . '% of first payment',
            'percent_recurring_months' => $pct((string) $rules['percent']) . '% of each payment for the first ' . (int) $rules['months'] . ' months',
            'flat_per_conversion'      => 'INR ' . $rules['amount'] . ' per conversion',
            'slab'                     => 'Slab on ' . (($rules['applies_to'] ?? 'first_payment') === 'first_payment' ? 'first payment' : 'payments for ' . (int) ($rules['months'] ?? 0) . ' months')
                . ': ' . implode('; ', array_map(static fn (array $sl): string => $sl['from'] . '–' . ($sl['to'] ?? '∞') . ' → '
                    . (isset($sl['percent']) ? $pct((string) $sl['percent']) . '%' : 'INR ' . $sl['amount']), $rules['slabs'] ?? [])),
            default                    => 'Commission',
        };
    }

    /** {published, name, version} of the published plan(s) for the seller portal. @return array<string,mixed> */
    public function planSummary(): array
    {
        $plans = $this->activePlans();
        if ($plans === []) {
            return ['published' => false, 'name' => null, 'version' => null];
        }
        $p = $plans[0];

        return ['published' => true, 'name' => count($plans) === 1 ? $p['name'] : implode(', ', array_column($plans, 'name')),
            'version' => count($plans) === 1 ? $p['version'] : null, 'rule_label' => count($plans) === 1 ? self::ruleLabel($p['rules']) : null];
    }

    /** @return array<string,string> totals by status */
    public function sellerTotals(int $sellerId): array
    {
        $totals = [];
        foreach (['accrued', 'approved', 'in_payout', 'paid', 'reversed'] as $st) {
            $totals[$st] = '0.00';
        }
        foreach ($this->db()->query('SELECT status, SUM(amount) AS amount FROM engage_commission_entries WHERE seller_id = ? GROUP BY status', [$sellerId])->getResultArray() as $r) {
            $totals[$r['status']] = Money::format(Money::toPaise((string) $r['amount']));
        }

        return $totals;
    }

    /** GET seller-portal commissions. @return array<string,mixed> */
    public function sellerSummary(int $sellerId, int $limit = 20, int $offset = 0, ?string $status = null): array
    {
        $b = $this->entriesQuery()->select('pr.name AS product_name, pl.rules AS plan_rules')
            ->join('engage_products pr', 'pr.code = e.product_code', 'left')
            ->join('engage_commission_plans pl', 'pl.id = e.plan_id')
            ->where('e.seller_id', $sellerId);
        if ($status !== null && $status !== '') {
            $b->where('e.status', $status);
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('e.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $items = array_map(static function (array $e): array {
            $rules = json_decode((string) $e['plan_rules'], true) ?: [];

            return [
                'id'            => 'CE-' . $e['id'],
                'lead_code'     => $e['lead_code'],
                'product'       => $e['product_name'] ?? $e['product_code'],
                'basis_amount'  => Money::format(Money::toPaise((string) $e['basis_amount'])),
                'rule_label'    => self::ruleLabel($rules),
                'plan_version'  => (int) $e['plan_version'],
                'amount'        => Money::format(Money::toPaise((string) $e['amount'])),
                'status'        => $e['status'],
                'accrued_at'    => Clock::iso($e['accrued_at']),
                'approve_after' => SellerLeadService::istDate((string) $e['hold_until']),
                'payout_ref'    => $e['payout_item_ref'],
                'reason'        => $e['reversal_reason'],
            ];
        }, $rows);

        return [
            'plan'   => $this->planSummary(),
            'message'=> $this->activePlans() === [] ? 'No commission plan published yet.' : null,
            'currency' => 'INR',
            'totals' => $this->sellerTotals($sellerId),
            'items'  => $items,
            'total'  => $total,
        ];
    }

    private function audit(string $event, int $planId, array $meta): void
    {
        Services::auditService()->log($event, [
            'subject_kind' => 'commission_plan', 'subject_id' => $planId, 'metadata' => $meta, 'fanout_console' => true,
        ]);
    }
}

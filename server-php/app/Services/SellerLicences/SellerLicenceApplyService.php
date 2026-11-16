<?php
declare(strict_types=1);

namespace App\Services\SellerLicences;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Licensing\LicenceService;
use App\Services\Licensing\OfferService;
use App\Services\Seller\SellerAccountService;
use App\Services\Tokens\CustomerNotifier;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * A seller applies a licence they bought to a customer's Aicountly account (docs/SELLING_LICENCES.md §7).
 *
 * The customer is found by the email they signed up with at my.aicountly.com — Engage hears of every sign-up through
 * Basic provisioning (engage_holders) — exact and case-insensitive, never a fuzzy search, and only masked details are
 * shown back. The seller confirms the customer asked for it. Lead protection is respected (another seller's protected
 * contact is refused with the generic answer). Then, in ONE transaction: the licence is issued or renewed
 * (LicenceService::applySellerUnitTx — Basic superseded, Manage told), the customer's lead is attributed to the seller
 * and converted, the sale is recorded on it as a payment through that seller and evaluated for commission
 * (CommissionService::recordSellerLicenceSale), and the unit is marked applied. A seller applying a licence to their
 * own account gets the licence and no commission.
 */
class SellerLicenceApplyService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** Holders who signed up with this email. @return list<array<string,mixed>> */
    private function holdersByEmail(string $email): array
    {
        return $this->db()->query('SELECT holder_uuid, email, name, signed_up_at FROM engage_holders WHERE LOWER(email) = ? ORDER BY signed_up_at DESC NULLS LAST LIMIT 2',
            [strtolower(trim($email))])->getResultArray();
    }

    private static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** "R*** K***" — enough for a seller to confirm they have the right person. */
    public static function maskName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return implode(' ', array_map(static fn (string $w): string => mb_substr($w, 0, 1) . '***', preg_split('/\s+/u', $name) ?: []));
    }

    /**
     * POST customers/lookup {email}: does an Aicountly account use this email, and may this seller sell to it?
     * Answers masked details only; every lookup is audited (the email as a hash).
     */
    public function lookup(array $seller, string $email): array
    {
        $email = strtolower(trim($email));
        if (! self::validEmail($email)) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Enter the email your customer signed up with.', 'details' => ['email' => 'A valid email address.']];
        }
        $holders = $this->holdersByEmail($email);
        Services::sellerAuth()->audit('seller.customer_lookup', $seller, ['email_sha256' => hash('sha256', $email), 'found' => count($holders)]);
        $signup = rtrim((string) env('SELLER_REFERRAL_APP_URL', 'https://my.aicountly.com/'), '/') . '/?ref=' . rawurlencode((string) $seller['seller_code']);
        if ($holders === []) {
            return ['status' => 200, 'data' => ['found' => false, 'signup_url' => $signup,
                'message' => 'No Aicountly account uses this email yet. Ask your customer to sign up with it (your link: ' . $signup . '), then apply the licence.']];
        }
        if (count($holders) > 1) {
            return ['status' => 409, 'code' => 'customer_ambiguous', 'error' => 'More than one Aicountly account uses this email. Contact Aicountly.'];
        }
        $block = Services::sellerLeads()->licenceSaleBlock($seller, $email);
        $h = $holders[0];

        return ['status' => 200, 'data' => [
            'found'    => true,
            'eligible' => $block === null,
            'reason'   => $block['error'] ?? null,
            'customer' => ['name_masked' => self::maskName($h['name'] ?? null), 'email_masked' => SellerAccountService::maskEmail($email)],
            // Their current paid plans for the products this seller has licences of, so the seller knows whether a
            // licence renews or is refused. Nothing else about the account.
            'plans'    => $block === null ? $this->plansFor((string) $h['holder_uuid'], (int) $seller['id']) : [],
        ]];
    }

    /** @return list<array{product_code:string, plan:string, offer_code:string, status:string, scope:string, ends_on:?string}> */
    private function plansFor(string $holder, int $sellerId): array
    {
        $rows = $this->db()->query("SELECT l.* FROM engage_licences l
            WHERE l.holder_uuid = ? AND NOT l.is_basic AND l.status IN ('active','expired','suspended','issued')
              AND l.product_code IN (SELECT DISTINCT product_code FROM engage_seller_licence_units WHERE seller_id = ? AND status = 'available')
            ORDER BY l.product_code, l.ends_at DESC NULLS FIRST", [$holder, $sellerId])->getResultArray();
        $out = [];
        foreach ($rows as $l) {
            $out[] = ['product_code' => (string) $l['product_code'], 'plan' => (string) (S::json($l['offer_snapshot'])['label'] ?? $l['offer_code']),
                'offer_code' => (string) $l['offer_code'], 'status' => LicenceService::effectiveStatus($l), 'scope' => LicenceService::scopeOf($l),
                'ends_on' => $l['ends_at'] !== null ? S::toDate((string) $l['ends_at'])->modify('-1 second')->format('Y-m-d') : null];
        }

        return $out;
    }

    /**
     * POST licence-stock/{unit_ref}/apply {customer_email, consent_confirmed: true}. Idempotent per $idem (the portal's
     * Idempotency-Key): the same unit and customer again answers the first result (200, replayed).
     */
    public function apply(array $seller, string $unitRef, array $in, ?string $idem): array
    {
        if ($seller['status'] !== 'active') {
            return ['status' => 403, 'code' => 'seller_not_active', 'error' => 'Your seller account must be active to apply licences.'];
        }
        $email = strtolower(trim((string) ($in['customer_email'] ?? '')));
        $e = [];
        if (! self::validEmail($email)) {
            $e['customer_email'] = 'The email your customer signed up with at my.aicountly.com.';
        }
        if (($in['consent_confirmed'] ?? null) !== true) {
            $e['consent_confirmed'] = 'Confirm that the customer asked you to apply this licence to their account.';
        }
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the highlighted fields.', 'details' => $e];
        }
        $hash = hash('sha256', $unitRef . '|' . $email);
        $unit = $this->db()->query('SELECT * FROM engage_seller_licence_units WHERE unit_ref = ? AND seller_id = ?', [$unitRef, (int) $seller['id']])->getRowArray();
        if (! $unit) {
            return ['status' => 404, 'code' => 'not_found', 'error' => 'No such licence in your stock.'];
        }
        if ($unit['status'] === 'applied') {
            return $idem !== null && $unit['idempotency_key'] === $idem && hash_equals((string) $unit['request_hash'], $hash)
                ? ['status' => 200, 'data' => $this->result($unit) + ['replayed' => true]]
                : ['status' => 409, 'code' => 'unit_used', 'error' => 'This licence was already applied (' . $unit['licence_ref'] . ').'];
        }
        if ($unit['status'] !== 'available') {
            return ['status' => 409, 'code' => 'unit_void', 'error' => 'This licence was withdrawn by Aicountly and cannot be applied.'];
        }
        $holders = $this->holdersByEmail($email);
        if ($holders === []) {
            return ['status' => 404, 'code' => 'customer_not_found', 'error' => 'No Aicountly account uses this email yet. Ask your customer to sign up with it first.'];
        }
        if (count($holders) > 1) {
            return ['status' => 409, 'code' => 'customer_ambiguous', 'error' => 'More than one Aicountly account uses this email. Contact Aicountly.'];
        }
        $selfUse = strtolower((string) $seller['email']) === $email;
        if (! $selfUse && ($block = Services::sellerLeads()->licenceSaleBlock($seller, $email)) !== null) {
            return $block;
        }
        $holder = (string) $holders[0]['holder_uuid'];
        $offers = new OfferService($this->db());
        $offer = $offers->findRow((int) $unit['offer_id']);
        $order = $this->db()->query('SELECT order_ref, invoice_no, paid_at FROM engage_seller_licence_orders WHERE id = ?', [(int) $unit['order_id']])->getRowArray();
        $line = $this->db()->query('SELECT unit_price_paise FROM engage_seller_licence_order_lines WHERE id = ?', [(int) $unit['line_id']])->getRowArray();
        if (! $offer || ! $order) {
            return ['status' => 500, 'error' => 'This licence cannot be applied right now. Contact Aicountly.'];
        }

        $db = $this->db();
        try {
            $r = S::strictTx($db, function () use ($db, $unit, $offer, $holder, $seller, $order, $line, $email, $holders, $selfUse, $idem, $hash): array {
                $db->transBegin();
                try {
                    $locked = $db->query("SELECT * FROM engage_seller_licence_units WHERE id = ? AND status = 'available' FOR UPDATE", [(int) $unit['id']])->getRowArray();
                    if (! $locked) {
                        $db->transRollback();

                        return ['status' => 409, 'code' => 'unit_used', 'error' => 'This licence was just applied. Reload your stock.'];
                    }
                    $lic = Services::licences()->applySellerUnitTx($locked, $offer, $holder, $seller, $order['invoice_no'] ?: null);
                    if (($lic['status'] ?? 500) >= 300) {
                        $db->transRollback();

                        return $lic;
                    }
                    $leadId = null;
                    $paymentId = null;
                    $note = 'Applied to your own account: no commission.';
                    $commission = null;
                    if (! $selfUse) {
                        $lead = Services::sellerLeads()->attachForLicenceSale($seller, $email, $holders[0]['name'] ?? null, (string) $offer['product_code']);
                        $leadId = $lead['lead_id'];
                        $sale = Services::commissions()->recordSellerLicenceSale($leadId, $locked, (int) $seller['id'], (int) ($line['unit_price_paise'] ?? 0),
                            (string) ($order['paid_at'] ?? gmdate('Y-m-d H:i:s') . '+00'), (string) $order['order_ref']);
                        $paymentId = $sale['payment_id'];
                        if ($lead['stage_from'] !== null) {
                            // Converted now: the Reach outcome (for a Reach lead) and the commission evaluation of every
                            // confirmed payment on it, as when staff convert a lead.
                            Services::leadLifecycle()->stageChanged($leadId, $lead['stage_from'], 'converted');
                        }
                        $entry = Services::commissions()->entryForPayment($paymentId);
                        $note = $entry !== null ? 'Commission ₹' . $entry['amount'] . ' accrued (' . $entry['explain'] . ').'
                            : (string) ($db->query('SELECT commission_note FROM engage_lead_payments WHERE id = ?', [$paymentId])->getRow()->commission_note ?? 'No commission accrued.');
                        $commission = $entry !== null ? ['amount' => $entry['amount'], 'status' => $entry['status'], 'approve_after' => \App\Services\Seller\SellerLeadService::istDate((string) $entry['hold_until'])] : null;
                    }
                    $db->query("UPDATE engage_seller_licence_units SET status = 'applied', applied_at = NOW(), apply_kind = ?, licence_ref = ?, holder_uuid = ?,
                            customer_email = ?, lead_id = ?, payment_id = ?, commission_note = ?, idempotency_key = ?, request_hash = ?, updated_at = NOW() WHERE id = ?",
                        [$lic['kind'], $lic['licence_ref'], $holder, $email, $leadId, $paymentId, mb_substr($note, 0, 255), $idem, $hash, (int) $locked['id']]);
                    $db->transCommit();

                    return ['status' => 200, 'unit_id' => (int) $locked['id'], 'kind' => $lic['kind'], 'commission' => $commission];
                } catch (\Throwable $ex) {
                    $db->transRollback();

                    throw $ex;
                }
            });
        } catch (\Throwable $ex) {
            log_message('error', "Seller licence {$unitRef}: apply failed: " . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not apply the licence. Nothing was changed.'];
        }
        if (($r['status'] ?? 500) !== 200) {
            return $r;
        }
        $applied = $this->db()->query('SELECT * FROM engage_seller_licence_units WHERE id = ?', [(int) $r['unit_id']])->getRowArray();
        Services::sellerAuth()->audit('seller.licence_applied', $seller, ['unit_ref' => $unitRef, 'licence_ref' => $applied['licence_ref'], 'kind' => $r['kind'],
            'holder_uuid' => $holder, 'self_use' => $selfUse]);
        $this->notifyCustomer($applied, $seller, $holders[0]);

        return ['status' => 200, 'data' => $this->result($applied)];
    }

    /** The answer to an apply: the unit, the licence as the customer now holds it, and the commission. */
    private function result(array $unit): array
    {
        $l = Services::licences()->findByRef((string) $unit['licence_ref']);
        $v = $l ? LicenceService::contractView($l) : null;

        return [
            'unit'       => self::unitView($unit),
            'kind'       => $unit['apply_kind'],
            // scope company + cmp_id null: the customer applies it to one of their companies in Manage (Plans & billing →
            // Licences); until then it grants nothing (docs/LICENSING.md §3.6).
            'licence'    => $v !== null ? ['licence_ref' => $v['licence_ref'], 'product' => $v['product'], 'plan' => $v['offer']['label'],
                'status' => $v['status'], 'term' => $v['term'], 'scope' => $v['scope'], 'cmp_id' => $v['cmp_id']] : null,
            'commission' => ['note' => $unit['commission_note']],
        ];
    }

    /**
     * Tell the customer a seller applied a licence to their account, through Console's `plan.changed` template (best
     * effort: never fails the apply). Off until ENGAGE_NOTIFY_SELLER_LICENCES=1 — switch it on once the template's
     * variables are confirmed in Console (docs/SELLING_LICENCES.md §10); Manage shows the licence either way.
     */
    private function notifyCustomer(array $unit, array $seller, array $holder): void
    {
        try {
            $l = Services::licences()->findByRef((string) $unit['licence_ref']);
            if (! $l || ! CustomerNotifier::configured() || ! filter_var(env('ENGAGE_NOTIFY_SELLER_LICENCES', '0'), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }
            $v = LicenceService::contractView($l);
            $sellerName = trim((string) ($seller['organization'] ?? '')) !== '' ? (string) $seller['organization'] : (string) $seller['name'];
            (new CustomerNotifier($this->db()))->sendFirstAvailable('plan.changed', ['email'], ['email' => $holder['email'] ?? null, 'name' => $holder['name'] ?? null, 'mobile' => null], [
                'product'     => (string) $v['product']['label'],
                'plan'        => (string) $v['offer']['label'],
                'ends_on'     => $l['ends_at'] !== null ? S::displayDate((string) $l['ends_at']) : '',
                'licence_ref' => (string) $v['licence_ref'],
                'change'      => $unit['apply_kind'] === 'renewal' ? 'renewed' : 'applied',
                'seller_name' => $sellerName,
                'seller_code' => (string) $seller['seller_code'],
            ], 'seller-licence:' . $unit['unit_ref']);
        } catch (\Throwable $e) {
            log_message('warning', 'Seller licence {u}: customer not notified: {m}', ['u' => $unit['unit_ref'], 'm' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ reads

    /** @return array<string,mixed> */
    public static function unitView(array $u): array
    {
        return [
            'unit_ref'      => $u['unit_ref'],
            'offer_code'    => $u['offer_code'],
            'offer_version' => (int) $u['offer_version'],
            'product_code'  => $u['product_code'],
            'label'         => $u['label'] ?? null,
            'status'        => $u['status'],
            'taxable_paise' => (int) $u['taxable_paise'],
            'total_paise'   => (int) $u['total_paise'],
            'order_ref'     => $u['order_ref'] ?? null,
            'applied_at'    => S::iso($u['applied_at'] ?? null),
            'apply_kind'    => $u['apply_kind'] ?? null,
            'licence_ref'   => $u['licence_ref'] ?? null,
            'customer_email_masked' => SellerAccountService::maskEmail($u['customer_email'] ?? null),
            'commission_note' => $u['commission_note'] ?? null,
            'void_reason'   => $u['void_reason'] ?? null,
            'created_at'    => S::iso($u['created_at'] ?? null),
        ];
    }

    /** GET licence-stock ?status=available|applied|void &product_code= &page= &limit= */
    public function stock(array $seller, array $q, int $limit, int $offset): array
    {
        $where = ['u.seller_id = ?'];
        $binds = [(int) $seller['id']];
        if (! empty($q['status']) && in_array($q['status'], ['available', 'applied', 'void'], true)) {
            $where[] = 'u.status = ?';
            $binds[] = (string) $q['status'];
        }
        if (! empty($q['product_code']) && is_string($q['product_code'])) {
            $where[] = 'u.product_code = ?';
            $binds[] = $q['product_code'];
        }
        $sql = 'FROM engage_seller_licence_units u JOIN engage_seller_licence_order_lines l ON l.id = u.line_id
            JOIN engage_seller_licence_orders o ON o.id = u.order_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db()->query('SELECT COUNT(*) AS n ' . $sql, $binds)->getRow()->n;
        $rows = $this->db()->query('SELECT u.*, l.label, o.order_ref ' . $sql . " ORDER BY (u.status = 'available') DESC, u.id DESC LIMIT ? OFFSET ?",
            array_merge($binds, [$limit, $offset]))->getResultArray();
        $summary = $this->db()->query("SELECT u.offer_code, l.label, u.product_code, COUNT(*) AS available FROM engage_seller_licence_units u
            JOIN engage_seller_licence_order_lines l ON l.id = u.line_id WHERE u.seller_id = ? AND u.status = 'available'
            GROUP BY u.offer_code, l.label, u.product_code ORDER BY u.product_code, u.offer_code", [(int) $seller['id']])->getResultArray();

        return ['items' => array_map([self::class, 'unitView'], $rows), 'total' => $total,
            'available' => array_map(static fn (array $s): array => ['offer_code' => $s['offer_code'], 'label' => $s['label'], 'product_code' => $s['product_code'], 'count' => (int) $s['available']], $summary)];
    }

    /** GET customer-licences: licences this seller sold (new or renewed), with their status and end — for renewals. */
    public function customerLicences(array $seller, array $q, int $limit, int $offset): array
    {
        $where = 'l.licence_ref IN (SELECT licence_ref FROM engage_seller_licence_units WHERE seller_id = ? AND status = \'applied\')';
        $binds = [(int) $seller['id']];
        $total = (int) $this->db()->query("SELECT COUNT(*) AS n FROM engage_licences l WHERE {$where}", $binds)->getRow()->n;
        $rows = $this->db()->query("SELECT l.*, (SELECT u.customer_email FROM engage_seller_licence_units u WHERE u.licence_ref = l.licence_ref AND u.seller_id = ?
                ORDER BY u.applied_at DESC LIMIT 1) AS customer_email
            FROM engage_licences l WHERE {$where} ORDER BY l.ends_at ASC NULLS LAST, l.id DESC LIMIT ? OFFSET ?",
            [(int) $seller['id'], (int) $seller['id'], $limit, $offset])->getResultArray();

        return ['total' => $total, 'items' => array_map(static function (array $l): array {
            $status = LicenceService::effectiveStatus($l);
            $endsAt = $l['ends_at'] !== null ? strtotime((string) $l['ends_at']) : null;

            return [
                'licence_ref'    => $l['licence_ref'],
                'product_code'   => $l['product_code'],
                'plan'           => (string) (S::json($l['offer_snapshot'])['label'] ?? $l['offer_code']),
                'offer_code'     => $l['offer_code'],
                'status'         => $status,
                'starts_at'      => S::iso($l['starts_at']),
                'ends_at'        => S::iso($l['ends_at']),
                'renewal_due'    => $endsAt !== null && in_array($status, ['active', 'expired'], true) && $endsAt <= time() + 30 * 86400,
                'customer_email_masked' => SellerAccountService::maskEmail($l['customer_email'] ?? null),
            ];
        }, $rows)];
    }
}

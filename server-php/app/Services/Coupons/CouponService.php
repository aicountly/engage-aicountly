<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use App\Libraries\Licensing\LicensingSupport as S;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Coupons: the rule (engage_coupons), its codes (engage_coupon_codes), lifecycle, approvals, distribution (CSV) and
 * reports — docs/COUPONS.md. Rights (Routes.php role filters, mirrored in the UI): create / edit / activate / pause /
 * retire / generate = super_admin | sales_manager; view / export = also seller_manager.
 *
 * Lifecycle:  draft ──activate (approval)──▶ active ⇄ paused ──▶ retired (final)
 *                  └─discard (delete) / retire        └─▶ expired (valid_to passed) | exhausted (total limit used) → retired
 *
 * Approval (four-eyes): activating a draft records approved_by / approved_at. A sales_manager may not approve a coupon
 * they created themselves; a super_admin may (recorded as the approver). Once active the rule is immutable (DB trigger
 * engage_coupons_guard) — to change a discount, retire the coupon and create another.
 *
 * Every result is ['status' => 2xx, 'data' => …] or ['status' => 4xx, 'error' => …, 'code'? , 'details'?].
 */
class CouponService
{
    public const STATUSES = ['draft', 'active', 'paused', 'expired', 'exhausted', 'retired'];
    public const MAX_BULK = 5000;

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    // ------------------------------------------------------------------ views

    /** Status as shown: an active / paused coupon past valid_to reads "expired" before the job records it. */
    public static function effectiveStatus(array $c): string
    {
        if (in_array($c['status'], ['active', 'paused'], true) && $c['valid_to'] !== null && strtotime((string) $c['valid_to']) <= time()) {
            return 'expired';
        }

        return (string) $c['status'];
    }

    public static function view(array $c, array $stats = []): array
    {
        $applies = S::json($c['applies_to']);

        return [
            'id'                         => (int) $c['id'],
            'coupon_ref'                 => $c['coupon_ref'],
            'code_kind'                  => $c['code_kind'],
            'label'                      => $c['label'],
            'description'                => $c['description'],
            'discount_type'              => $c['discount_type'],
            'percent_bp'                 => $c['percent_bp'] !== null ? (int) $c['percent_bp'] : null,
            'flat_paise'                 => $c['flat_paise'] !== null ? (int) $c['flat_paise'] : null,
            'max_discount_paise'         => $c['max_discount_paise'] !== null ? (int) $c['max_discount_paise'] : null,
            'min_order_paise'            => $c['min_order_paise'] !== null ? (int) $c['min_order_paise'] : null,
            'applies_to'                 => [
                'product_codes'      => array_values((array) ($applies['product_codes'] ?? [])),
                'offer_codes'        => array_values((array) ($applies['offer_codes'] ?? [])),
                'licence_type_codes' => array_values((array) ($applies['licence_type_codes'] ?? [])),
            ],
            'new_or_renewal'             => $c['new_or_renewal'],
            'valid_from'                 => S::iso($c['valid_from']),
            'valid_to'                   => S::iso($c['valid_to']),
            'max_redemptions_total'      => $c['max_redemptions_total'] !== null ? (int) $c['max_redemptions_total'] : null,
            'max_redemptions_per_holder' => $c['max_redemptions_per_holder'] !== null ? (int) $c['max_redemptions_per_holder'] : null,
            'stackable'                  => false,
            'seller'                     => $c['seller_id'] !== null ? ['id' => (int) $c['seller_id'], 'seller_code' => $c['seller_code'] ?? null] : null,
            'campaign_ref'               => $c['campaign_ref'],
            'status'                     => self::effectiveStatus($c),
            'stored_status'              => $c['status'],
            'created_by'                 => $c['created_by'] !== null ? (int) $c['created_by'] : null,
            'created_by_email'           => $c['created_by_email'] ?? null,
            'approved_by'                => $c['approved_by'] !== null ? (int) $c['approved_by'] : null,
            'approved_by_email'          => $c['approved_by_email'] ?? null,
            'approved_at'                => S::iso($c['approved_at']),
            'activated_at'               => S::iso($c['activated_at']),
            'retired_at'                 => S::iso($c['retired_at']),
            'version'                    => (int) $c['version'],
            'created_at'                 => S::iso($c['created_at']),
            'updated_at'                 => S::iso($c['updated_at']),
            'stats'                      => $stats + ['codes' => 0, 'codes_available' => 0, 'reserved' => 0, 'committed' => 0,
                'discount_committed_paise' => 0, 'net_committed_paise' => 0],
        ];
    }

    private const SELECT = 'SELECT c.*, s.seller_code, cu.email AS created_by_email, au.email AS approved_by_email
        FROM engage_coupons c
        LEFT JOIN engage_sellers s ON s.id = c.seller_id
        LEFT JOIN engage_users cu ON cu.id = c.created_by
        LEFT JOIN engage_users au ON au.id = c.approved_by';

    public function find(int $id): ?array
    {
        return $this->db()->query(self::SELECT . ' WHERE c.id = ?', [$id])->getRowArray() ?: null;
    }

    /** @return array<int, array<string,int>> coupon_id => stats */
    private function stats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->db()->query("SELECT coupon_id, COUNT(*) AS n, COUNT(*) FILTER (WHERE status = 'available') AS a
                FROM engage_coupon_codes WHERE coupon_id IN ({$in}) GROUP BY coupon_id")->getResultArray() as $r) {
            $out[(int) $r['coupon_id']]['codes'] = (int) $r['n'];
            $out[(int) $r['coupon_id']]['codes_available'] = (int) $r['a'];
        }
        foreach ($this->db()->query("SELECT coupon_id,
                    COUNT(*) FILTER (WHERE state = 'reserved' AND reserved_until > NOW()) AS r,
                    COUNT(*) FILTER (WHERE state = 'committed') AS c,
                    COALESCE(SUM(discount_paise) FILTER (WHERE state = 'committed'), 0) AS d,
                    COALESCE(SUM(net_paise) FILTER (WHERE state = 'committed'), 0) AS net
                FROM engage_coupon_redemptions WHERE coupon_id IN ({$in}) GROUP BY coupon_id")->getResultArray() as $r) {
            $id = (int) $r['coupon_id'];
            $out[$id]['reserved'] = (int) $r['r'];
            $out[$id]['committed'] = (int) $r['c'];
            $out[$id]['discount_committed_paise'] = (int) $r['d'];
            $out[$id]['net_committed_paise'] = (int) $r['net'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $where = [];
        $binds = [];
        $status = (string) ($q['status'] ?? '');
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            // Filter on the status as shown (an ended active coupon is listed as expired).
            if ($status === 'expired') {
                $where[] = "(c.status = 'expired' OR (c.status IN ('active','paused') AND c.valid_to IS NOT NULL AND c.valid_to <= NOW()))";
            } elseif (in_array($status, ['active', 'paused'], true)) {
                $where[] = 'c.status = ? AND (c.valid_to IS NULL OR c.valid_to > NOW())';
                $binds[] = $status;
            } else {
                $where[] = 'c.status = ?';
                $binds[] = $status;
            }
        }
        if (! empty($q['code_kind']) && in_array($q['code_kind'], ['shared', 'single_use'], true)) {
            $where[] = 'c.code_kind = ?';
            $binds[] = $q['code_kind'];
        }
        if (! empty($q['seller_id']) && ctype_digit((string) $q['seller_id'])) {
            $where[] = 'c.seller_id = ?';
            $binds[] = (int) $q['seller_id'];
        }
        if (! empty($q['campaign_ref']) && is_string($q['campaign_ref'])) {
            $where[] = 'c.campaign_ref = ?';
            $binds[] = $q['campaign_ref'];
        }
        if (! empty($q['q']) && is_string($q['q'])) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr(trim($q['q']), 0, 64)) . '%';
            $code = CodeGenerator::normalise($q['q']);
            $where[] = '(c.label ILIKE ? OR c.coupon_ref ILIKE ? OR EXISTS (SELECT 1 FROM engage_coupon_codes k WHERE k.coupon_id = c.id AND k.code = ?))';
            array_push($binds, $term, $term, $code);
        }
        $rows = $this->db()->query(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.id DESC LIMIT 500', $binds)->getResultArray();
        $stats = $this->stats(array_map(static fn ($r) => (int) $r['id'], $rows));

        return array_map(static fn ($r) => self::view($r, $stats[(int) $r['id']] ?? []), $rows);
    }

    public function detail(int $id): ?array
    {
        $c = $this->find($id);
        if (! $c) {
            return null;
        }

        return self::view($c, $this->stats([$id])[$id] ?? []);
    }

    // ------------------------------------------------------------------ validation

    /** "10" / "12.5" percent → basis points (1000 / 1250); null when not 0.01–100 with ≤ 2 decimals. */
    public static function percentToBp(mixed $v): ?int
    {
        $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : (is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : ''));
        if (! preg_match('/\A(\d{1,3})(?:\.(\d{1,2}))?\z/', $s, $m)) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $bp >= 1 && $bp <= 10000 ? $bp : null;
    }

    private static function paise(mixed $v, bool $allowZero = false): ?int
    {
        if (is_int($v)) {
            return ($v > 0 || ($allowZero && $v === 0)) && $v <= 100_000_000_000 ? $v : null;
        }
        if (is_string($v) && preg_match('/\A\d{1,12}\z/', trim($v))) {
            return self::paise((int) trim($v), $allowZero);
        }

        return null;
    }

    private static function posInt(mixed $v): ?int
    {
        if (is_int($v) || (is_string($v) && ctype_digit(trim($v)) && trim($v) !== '')) {
            $n = (int) $v;

            return $n >= 1 && $n <= 10_000_000 ? $n : null;
        }

        return null;
    }

    /** A typed valid_to date (YYYY-MM-DD) is the LAST day the code works → stored as 00:00 IST the day after. */
    public static function validTo(mixed $v): ?string
    {
        if (is_string($v) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', trim($v))) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v), new \DateTimeZone(S::TZ));
            if ($d === false || $d->format('Y-m-d') !== trim($v)) {
                return null;
            }

            return S::db($d->modify('+1 day'));
        }

        return S::parseInstant($v);
    }

    /** @return list<string> */
    private static function codeList(mixed $v, string $pattern): ?array
    {
        if ($v === null || $v === '' || $v === []) {
            return [];
        }
        if (is_string($v)) {
            $v = array_map('trim', explode(',', $v));
        }
        if (! is_array($v)) {
            return null;
        }
        $out = [];
        foreach ($v as $x) {
            if (! is_string($x)) {
                return null;
            }
            $x = strtolower(trim($x));
            if ($x === '') {
                continue;
            }
            if (! preg_match($pattern, $x)) {
                return null;
            }
            $out[$x] = true;
        }

        return array_keys($out);
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function fields(array $in, ?array $current = null): array
    {
        $e = [];
        $f = [];
        $has = static fn (string $k) => array_key_exists($k, $in);
        $cur = static fn (string $k) => $current[$k] ?? null;

        $kind = $has('code_kind') ? (string) $in['code_kind'] : ($cur('code_kind') ?? '');
        if (! in_array($kind, ['shared', 'single_use'], true)) {
            $e['code_kind'] = 'shared (one code many customers use) or single_use (bulk codes, one use each).';
        } elseif ($current && $kind !== $current['code_kind'] && (int) $this->db()->table('engage_coupon_codes')->where('coupon_id', (int) $current['id'])->countAllResults() > 0) {
            $e['code_kind'] = 'Codes exist for this coupon; the code kind can no longer change.';
        }
        $f['code_kind'] = $kind;

        $label = trim((string) ($has('label') ? $in['label'] : ($cur('label') ?? '')));
        if ($label === '' || mb_strlen($label) > 120) {
            $e['label'] = 'A label (1–120 characters).';
        }
        $f['label'] = $label;
        $desc = $has('description') ? trim((string) $in['description']) : ($cur('description') ?? '');
        $f['description'] = $desc === '' ? null : mb_substr((string) $desc, 0, 2000);

        $type = $has('discount_type') ? (string) $in['discount_type'] : ($cur('discount_type') ?? '');
        $f['discount_type'] = $type;
        $f['percent_bp'] = null;
        $f['flat_paise'] = null;
        if ($type === 'percent') {
            if ($has('percent_bp') && $in['percent_bp'] !== null && $in['percent_bp'] !== '') {
                $bp = is_int($in['percent_bp']) || (is_string($in['percent_bp']) && ctype_digit($in['percent_bp'])) ? (int) $in['percent_bp'] : null;
                $bp = $bp !== null && $bp >= 1 && $bp <= 10000 ? $bp : null;
            } elseif ($has('percent')) {
                $bp = self::percentToBp($in['percent']);
            } else {
                $bp = $cur('percent_bp') !== null ? (int) $cur('percent_bp') : null;
            }
            if ($bp === null) {
                $e['percent_bp'] = 'The percentage: 0.01–100 (percent_bp 1–10000, e.g. 1000 = 10 %).';
            }
            $f['percent_bp'] = $bp;
        } elseif ($type === 'flat') {
            $flat = self::paise($has('flat_paise') ? $in['flat_paise'] : $cur('flat_paise'));
            if ($flat === null) {
                $e['flat_paise'] = 'The amount off in paise (more than 0).';
            }
            $f['flat_paise'] = $flat;
        } else {
            $e['discount_type'] = 'percent or flat.';
        }

        foreach (['max_discount_paise' => false, 'min_order_paise' => true] as $k => $zero) {
            $v = $has($k) ? $in[$k] : $cur($k);
            if ($v === null || $v === '') {
                $f[$k] = null;
            } else {
                $p = self::paise($v, $zero);
                if ($p === null) {
                    $e[$k] = 'An amount in paise' . ($zero ? ' (0 or more)' : ' (more than 0)') . ', or empty.';
                }
                $f[$k] = $p;
            }
        }
        if ($type === 'flat' && $f['max_discount_paise'] !== null) {
            $f['max_discount_paise'] = null; // a cap only makes sense for a percentage
        }

        $applies = $has('applies_to') ? $in['applies_to'] : S::json($cur('applies_to') ?? '{}');
        if (! is_array($applies)) {
            $e['applies_to'] = 'An object {product_codes[], offer_codes[], licence_type_codes[]} (empty lists = any).';
            $applies = [];
        }
        $pc = self::codeList($applies['product_codes'] ?? [], '/\A[a-z0-9][a-z0-9_\-]{0,63}\z/');
        $oc = self::codeList($applies['offer_codes'] ?? [], '/\A[a-z0-9][a-z0-9_\-]{1,63}\z/');
        $tc = self::codeList($applies['licence_type_codes'] ?? [], '/\A[a-z0-9][a-z0-9_\-]{1,47}\z/');
        if ($pc === null || $oc === null || $tc === null) {
            $e['applies_to'] = 'Lists of product, offer and licence type codes (lower case).';
        } elseif ($pc !== []) {
            $known = array_column($this->db()->table('engage_products')->select('code')->whereIn('code', $pc)->get()->getResultArray(), 'code');
            $unknown = array_values(array_diff($pc, $known));
            if ($unknown !== []) {
                $e['applies_to'] = 'Unknown product code(s): ' . implode(', ', $unknown) . '.';
            }
        }
        $f['applies_to'] = ['product_codes' => $pc ?? [], 'offer_codes' => $oc ?? [], 'licence_type_codes' => $tc ?? []];

        $nr = $has('new_or_renewal') ? (string) $in['new_or_renewal'] : ($cur('new_or_renewal') ?? 'any');
        if (! in_array($nr, ['any', 'new', 'renewal'], true)) {
            $e['new_or_renewal'] = 'any, new or renewal.';
        }
        $f['new_or_renewal'] = $nr;

        if ($has('valid_from')) {
            $f['valid_from'] = $in['valid_from'] === null || $in['valid_from'] === '' ? null : S::parseInstant($in['valid_from']);
            if ($in['valid_from'] !== null && $in['valid_from'] !== '' && $f['valid_from'] === null) {
                $e['valid_from'] = 'A date (YYYY-MM-DD) or an ISO-8601 time.';
            }
        } else {
            $f['valid_from'] = $cur('valid_from');
        }
        if ($has('valid_to')) {
            $f['valid_to'] = $in['valid_to'] === null || $in['valid_to'] === '' ? null : self::validTo($in['valid_to']);
            if ($in['valid_to'] !== null && $in['valid_to'] !== '' && $f['valid_to'] === null) {
                $e['valid_to'] = 'The last day the code works (YYYY-MM-DD) or an ISO-8601 time.';
            }
        } else {
            $f['valid_to'] = $cur('valid_to');
        }
        if ($f['valid_from'] !== null && $f['valid_to'] !== null && strtotime((string) $f['valid_to']) <= strtotime((string) $f['valid_from'])) {
            $e['valid_to'] = 'Must be after valid_from.';
        }

        foreach (['max_redemptions_total', 'max_redemptions_per_holder'] as $k) {
            $v = $has($k) ? $in[$k] : $cur($k);
            if ($v === null || $v === '') {
                $f[$k] = null;
            } else {
                $n = self::posInt($v);
                if ($n === null) {
                    $e[$k] = 'A whole number of 1 or more, or empty for no limit.';
                }
                $f[$k] = $n;
            }
        }
        if ($f['max_redemptions_total'] !== null && $f['max_redemptions_per_holder'] !== null && $f['max_redemptions_per_holder'] > $f['max_redemptions_total']) {
            $e['max_redemptions_per_holder'] = 'Cannot exceed the total limit.';
        }
        if ($has('stackable') && filter_var($in['stackable'], FILTER_VALIDATE_BOOLEAN)) {
            $e['stackable'] = 'Coupons are not stackable: one coupon per purchase.';
        }

        $f['seller_id'] = $cur('seller_id') !== null ? (int) $cur('seller_id') : null;
        if ($has('seller_code') || $has('seller_id')) {
            $sc = $has('seller_code') ? trim((string) $in['seller_code']) : '';
            $sid = $has('seller_id') && $in['seller_id'] !== null && $in['seller_id'] !== '' ? (int) $in['seller_id'] : null;
            $seller = null;
            if ($sc !== '') {
                $seller = $this->db()->table('engage_sellers')->where('seller_code', strtoupper($sc))->get()->getRowArray();
            } elseif ($sid !== null) {
                $seller = $this->db()->table('engage_sellers')->where('id', $sid)->get()->getRowArray();
            }
            if (($sc !== '' || $sid !== null) && (! $seller || $seller['status'] === 'terminated')) {
                $e['seller_code'] = 'No such seller (or the seller is terminated).';
            }
            $f['seller_id'] = $seller ? (int) $seller['id'] : null;
        }
        $camp = $has('campaign_ref') ? trim((string) $in['campaign_ref']) : ($cur('campaign_ref') ?? '');
        if ($camp !== '' && ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\-]{0,63}\z/', (string) $camp)) {
            $e['campaign_ref'] = 'Letters, digits, _ . : - (up to 64).';
        }
        $f['campaign_ref'] = $camp === '' ? null : $camp;

        return [$f, $e];
    }

    private static function invalid(array $e, string $msg = 'Please correct the coupon.'): array
    {
        return ['status' => 422, 'error' => $msg, 'details' => $e];
    }

    private static function conflict(string $code, string $msg, array $details = []): array
    {
        return ['status' => 409, 'code' => $code, 'error' => $msg] + ($details ? ['details' => $details] : []);
    }

    // ------------------------------------------------------------------ CRUD

    public function createDraft(array $in, array $staff): array
    {
        return Tx::strict($this->db(), fn () => $this->createDraftTx($in, $staff));
    }

    private function createDraftTx(array $in, array $staff): array
    {
        [$f, $e] = $this->fields($in);
        $sharedCode = null;
        if ($f['code_kind'] === 'shared' && isset($in['code']) && $in['code'] !== '' && $in['code'] !== null) {
            $sharedCode = CodeGenerator::normalise($in['code']);
            if (! CodeGenerator::isValidShared($sharedCode)) {
                $e['code'] = 'A shared code is 4–20 letters or digits (spaces and hyphens are ignored).';
            } elseif ($this->db()->table('engage_coupon_codes')->where('code', $sharedCode)->countAllResults() > 0) {
                $e['code'] = 'That code is already used by another coupon.';
            }
        }
        if ($e !== []) {
            return self::invalid($e);
        }
        $db = $this->db();
        $db->transBegin();
        try {
            $n = (int) $db->query("SELECT nextval('engage_coupon_ref_seq') AS n")->getRow()->n;
            $ref = 'CPN-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $id = (int) $db->query(
                'INSERT INTO engage_coupons (coupon_ref, code_kind, label, description, discount_type, percent_bp, flat_paise, max_discount_paise,
                    min_order_paise, applies_to, new_or_renewal, valid_from, valid_to, max_redemptions_total, max_redemptions_per_holder,
                    seller_id, campaign_ref, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$ref, $f['code_kind'], $f['label'], $f['description'], $f['discount_type'], $f['percent_bp'], $f['flat_paise'],
                    $f['max_discount_paise'], $f['min_order_paise'], json_encode($f['applies_to']), $f['new_or_renewal'], $f['valid_from'],
                    $f['valid_to'], $f['max_redemptions_total'], $f['max_redemptions_per_holder'], $f['seller_id'], $f['campaign_ref'], (int) $staff['id']]
            )->getRow()->id;
            if ($sharedCode !== null) {
                $db->query('INSERT INTO engage_coupon_codes (coupon_id, code, kind, created_by) VALUES (?, ?, ?, ?)', [$id, $sharedCode, 'shared', (int) $staff['id']]);
            }
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();
            log_message('error', 'Coupon create failed: ' . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not create the coupon.'];
        }
        $this->audit('coupon.created', $id, ['coupon_ref' => $ref, 'code_kind' => $f['code_kind'], 'discount_type' => $f['discount_type'],
            'percent_bp' => $f['percent_bp'], 'flat_paise' => $f['flat_paise'], 'seller_id' => $f['seller_id']]);

        return ['status' => 201, 'data' => $this->detail($id)];
    }

    private function versionMismatch(array $c, array $in): ?array
    {
        if (array_key_exists('version', $in) && $in['version'] !== null && $in['version'] !== '' && (int) $in['version'] !== (int) $c['version']) {
            return self::conflict('version_conflict', 'This coupon was changed by someone else. Reload and try again.', ['current_version' => (int) $c['version']]);
        }

        return null;
    }

    public function updateDraft(int $id, array $in, array $staff): array
    {
        $c = $this->find($id);
        if (! $c) {
            return ['status' => 404, 'error' => 'Coupon not found.'];
        }
        if ($c['status'] !== 'draft') {
            return self::conflict('immutable', 'Only a draft can be edited. An active coupon is immutable — retire it and create another.');
        }
        if ($m = $this->versionMismatch($c, $in)) {
            return $m;
        }
        [$f, $e] = $this->fields($in, $c);
        if ($e !== []) {
            return self::invalid($e);
        }
        $this->db()->query(
            'UPDATE engage_coupons SET code_kind = ?, label = ?, description = ?, discount_type = ?, percent_bp = ?, flat_paise = ?,
                max_discount_paise = ?, min_order_paise = ?, applies_to = ?::jsonb, new_or_renewal = ?, valid_from = ?, valid_to = ?,
                max_redemptions_total = ?, max_redemptions_per_holder = ?, seller_id = ?, campaign_ref = ?, version = version + 1, updated_at = NOW()
             WHERE id = ? AND version = ? AND status = ?',
            [$f['code_kind'], $f['label'], $f['description'], $f['discount_type'], $f['percent_bp'], $f['flat_paise'], $f['max_discount_paise'],
                $f['min_order_paise'], json_encode($f['applies_to']), $f['new_or_renewal'], $f['valid_from'], $f['valid_to'],
                $f['max_redemptions_total'], $f['max_redemptions_per_holder'], $f['seller_id'], $f['campaign_ref'], $id, (int) $c['version'], 'draft']
        );
        if ($this->db()->affectedRows() !== 1) {
            return self::conflict('version_conflict', 'This coupon was changed by someone else. Reload and try again.');
        }
        $this->audit('coupon.updated', $id, ['coupon_ref' => $c['coupon_ref']]);

        return ['status' => 200, 'data' => $this->detail($id)];
    }

    public function discardDraft(int $id, array $staff): array
    {
        $c = $this->find($id);
        if (! $c) {
            return ['status' => 404, 'error' => 'Coupon not found.'];
        }
        if ($c['status'] !== 'draft') {
            return self::conflict('invalid_transition', 'Only a draft can be discarded; retire an active coupon instead.');
        }
        $this->db()->query('DELETE FROM engage_coupons WHERE id = ? AND status = ?', [$id, 'draft']);
        $this->audit('coupon.discarded', $id, ['coupon_ref' => $c['coupon_ref']]);

        return ['status' => 200, 'data' => ['deleted' => true]];
    }

    // ------------------------------------------------------------------ lifecycle

    /** Activate a draft (= approve it) or resume a paused coupon. */
    public function activate(int $id, array $in, array $staff): array
    {
        $c = $this->find($id);
        if (! $c) {
            return ['status' => 404, 'error' => 'Coupon not found.'];
        }
        if ($m = $this->versionMismatch($c, $in)) {
            return $m;
        }
        if (! in_array($c['status'], ['draft', 'paused'], true)) {
            return self::conflict('invalid_transition', "A {$c['status']} coupon cannot be activated.");
        }
        if ($c['valid_to'] !== null && strtotime((string) $c['valid_to']) <= time()) {
            return self::conflict('invalid_transition', 'The validity of this coupon has ended.');
        }
        if ($c['status'] === 'draft') {
            $roles = (array) ($staff['roles'] ?? []);
            if ((int) $c['created_by'] === (int) $staff['id'] && ! in_array('super_admin', $roles, true)) {
                return self::conflict('four_eyes', 'You created this coupon — another sales manager or an administrator must approve (activate) it.');
            }
            $codes = (int) $this->db()->table('engage_coupon_codes')->where('coupon_id', $id)->where('status', 'available')->countAllResults();
            if ($codes === 0) {
                return self::conflict('no_codes', 'Add a code (shared) or generate codes (single-use) before activating.');
            }
            $this->db()->query("UPDATE engage_coupons SET status = 'active', approved_by = ?, approved_at = NOW(), activated_at = NOW(),
                    version = version + 1, updated_at = NOW() WHERE id = ? AND status = 'draft' AND version = ?", [(int) $staff['id'], $id, (int) $c['version']]);
            $event = 'coupon.activated';
        } else {
            $this->db()->query("UPDATE engage_coupons SET status = 'active', paused_at = NULL, version = version + 1, updated_at = NOW()
                    WHERE id = ? AND status = 'paused' AND version = ?", [$id, (int) $c['version']]);
            $event = 'coupon.resumed';
        }
        if ($this->db()->affectedRows() !== 1) {
            return self::conflict('version_conflict', 'This coupon was changed by someone else. Reload and try again.');
        }
        $this->audit($event, $id, ['coupon_ref' => $c['coupon_ref'], 'approved_by' => (int) $staff['id']]);

        return ['status' => 200, 'data' => $this->detail($id)];
    }

    public function pause(int $id, array $in, array $staff): array
    {
        return $this->move($id, $in, ['active'], 'paused', 'paused_at = NOW()', 'coupon.paused');
    }

    public function retire(int $id, array $in, array $staff): array
    {
        return $this->move($id, $in, ['draft', 'active', 'paused', 'expired', 'exhausted'], 'retired', 'retired_at = NOW(), retired_by = ' . (int) $staff['id'], 'coupon.retired');
    }

    private function move(int $id, array $in, array $from, string $to, string $set, string $event): array
    {
        $c = $this->find($id);
        if (! $c) {
            return ['status' => 404, 'error' => 'Coupon not found.'];
        }
        if ($m = $this->versionMismatch($c, $in)) {
            return $m;
        }
        if (! in_array($c['status'], $from, true)) {
            return self::conflict('invalid_transition', "A {$c['status']} coupon cannot become {$to}.");
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        $this->db()->query("UPDATE engage_coupons SET status = ?, {$set}, version = version + 1, updated_at = NOW() WHERE id = ? AND status = ? AND version = ?",
            [$to, $id, $c['status'], (int) $c['version']]);
        if ($this->db()->affectedRows() !== 1) {
            return self::conflict('version_conflict', 'This coupon was changed by someone else. Reload and try again.');
        }
        $this->audit($event, $id, ['coupon_ref' => $c['coupon_ref'], 'from' => $c['status'], 'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null]);

        return ['status' => 200, 'data' => $this->detail($id)];
    }

    /** System: active / paused coupons whose validity ended → expired. @return int coupons expired */
    public function expireEnded(): int
    {
        $rows = $this->db()->query("UPDATE engage_coupons SET status = 'expired', ended_at = NOW(), version = version + 1, updated_at = NOW()
            WHERE status IN ('active','paused') AND valid_to IS NOT NULL AND valid_to <= NOW() RETURNING id, coupon_ref")->getResultArray();
        foreach ($rows as $r) {
            $this->audit('coupon.expired', (int) $r['id'], ['coupon_ref' => $r['coupon_ref']], true);
        }

        return count($rows);
    }

    // ------------------------------------------------------------------ codes

    /**
     * Shared coupon: {code?} adds one code (typed, or generated when empty).
     * Single-use coupon: {count 1–5000, prefix?} generates a batch.
     */
    public function addCodes(int $id, array $in, array $staff): array
    {
        return Tx::strict($this->db(), fn () => $this->addCodesTx($id, $in, $staff));
    }

    private function addCodesTx(int $id, array $in, array $staff): array
    {
        $c = $this->find($id);
        if (! $c) {
            return ['status' => 404, 'error' => 'Coupon not found.'];
        }
        if (! in_array($c['status'], ['draft', 'active', 'paused'], true)) {
            return self::conflict('invalid_transition', "Codes cannot be added to a {$c['status']} coupon.");
        }
        $db = $this->db();
        if ($c['code_kind'] === 'shared') {
            $typed = CodeGenerator::normalise($in['code'] ?? '');
            if ($typed !== '' && ! CodeGenerator::isValidShared($typed)) {
                return self::invalid(['code' => 'A shared code is 4–20 letters or digits (spaces and hyphens are ignored).']);
            }
            for ($try = 0; $try < 5; $try++) {
                $code = $typed !== '' ? $typed : CodeGenerator::sharedGenerated();
                $row = $db->query('INSERT INTO engage_coupon_codes (coupon_id, code, kind, created_by) VALUES (?, ?, ?, ?) ON CONFLICT (code) DO NOTHING RETURNING id',
                    [$id, $code, 'shared', (int) $staff['id']])->getRow();
                if ($row) {
                    $this->audit('coupon.code_added', $id, ['coupon_ref' => $c['coupon_ref'], 'generated' => $typed === '']);

                    return ['status' => 201, 'data' => ['codes' => [$code], 'count' => 1]];
                }
                if ($typed !== '') {
                    return self::invalid(['code' => 'That code is already used by another coupon.']);
                }
            }

            return ['status' => 500, 'error' => 'Could not generate a unique code. Try again.'];
        }

        $count = self::posInt($in['count'] ?? null);
        $prefix = CodeGenerator::normalise($in['prefix'] ?? '');
        $e = [];
        if ($count === null || $count > self::MAX_BULK) {
            $e['count'] = 'How many codes: 1–' . self::MAX_BULK . '.';
        }
        if (! CodeGenerator::isValidPrefix($prefix)) {
            $e['prefix'] = 'Optional prefix: up to 8 characters from ' . CodeGenerator::ALPHABET . ' (no 0, O, 1, I).';
        }
        if ($e !== []) {
            return self::invalid($e);
        }
        $batch = 'B' . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
        $made = 0;
        $db->transBegin();
        try {
            for ($round = 0; $made < $count && $round < 10; $round++) {
                $want = $count - $made;
                $vals = [];
                $binds = [];
                for ($i = 0; $i < $want; $i++) {
                    $vals[] = '(?, ?, ?, ?, ?)';
                    array_push($binds, $id, CodeGenerator::singleUse($prefix), 'single_use', $batch, (int) $staff['id']);
                }
                foreach (array_chunk($vals, 500) as $k => $chunk) {
                    $b = array_slice($binds, $k * 500 * 5, count($chunk) * 5);
                    $db->query('INSERT INTO engage_coupon_codes (coupon_id, code, kind, batch_ref, created_by) VALUES ' . implode(',', $chunk) . ' ON CONFLICT (code) DO NOTHING', $b);
                    $made += $db->affectedRows();
                }
            }
            if ($made !== $count) {
                throw new \RuntimeException('could not generate enough unique codes');
            }
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();
            log_message('error', 'Coupon code generation failed: ' . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not generate the codes.'];
        }
        $this->audit('coupon.codes_generated', $id, ['coupon_ref' => $c['coupon_ref'], 'count' => $count, 'batch_ref' => $batch, 'prefix' => $prefix ?: null]);

        return ['status' => 201, 'data' => ['batch_ref' => $batch, 'count' => $count]];
    }

    /** @return array{items: list<array<string,mixed>>, total:int} */
    public function codes(int $id, array $q = []): array
    {
        $b = $this->db()->table('engage_coupon_codes')->where('coupon_id', $id);
        if (! empty($q['status']) && in_array($q['status'], ['available', 'reserved', 'redeemed', 'void'], true)) {
            $b->where('status', $q['status']);
        }
        if (! empty($q['batch_ref']) && is_string($q['batch_ref'])) {
            $b->where('batch_ref', $q['batch_ref']);
        }
        $total = (int) (clone $b)->countAllResults(false);
        $limit = min(500, max(1, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $rows = $b->orderBy('id')->limit($limit, $offset)->get()->getResultArray();

        return ['total' => $total, 'items' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'code' => $r['code'], 'display' => CodeGenerator::display($r['code']), 'kind' => $r['kind'],
            'status' => $r['status'], 'batch_ref' => $r['batch_ref'], 'created_at' => S::iso($r['created_at']),
            'exported_at' => S::iso($r['exported_at']), 'export_count' => (int) $r['export_count'],
        ], $rows)];
    }

    /** CSV of codes for distribution (audited; marks the codes as exported). @return array{status:int, csv?:string, filename?:string, error?:string} */
    public function exportCodes(int $id, array $q, array $staff): array
    {
        $c = $this->find($id);
        if (! $c) {
            return ['status' => 404, 'error' => 'Coupon not found.'];
        }
        $status = (string) ($q['status'] ?? 'available');
        $statuses = $status === 'all' ? ['available', 'reserved', 'redeemed', 'void'] : [$status];
        if (array_diff($statuses, ['available', 'reserved', 'redeemed', 'void']) !== []) {
            return self::invalid(['status' => 'available (default), reserved, redeemed, void or all.']);
        }
        $b = $this->db()->table('engage_coupon_codes')->where('coupon_id', $id)->whereIn('status', $statuses);
        if (! empty($q['batch_ref']) && is_string($q['batch_ref'])) {
            $b->where('batch_ref', $q['batch_ref']);
        }
        $rows = $b->orderBy('id')->get()->getResultArray();
        $esc = static fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $validTo = $c['valid_to'] !== null ? S::displayDate(gmdate('Y-m-d H:i:s', strtotime((string) $c['valid_to']) - 1) . '+00') : '';
        $offer = $c['discount_type'] === 'percent'
            ? rtrim(rtrim(number_format(((int) $c['percent_bp']) / 100, 2, '.', ''), '0'), '.') . '% off'
            : 'Rs ' . number_format(((int) $c['flat_paise']) / 100, 2, '.', '') . ' off';
        $lines = ['code,display,coupon_ref,label,offer,valid_until,status,batch_ref'];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map($esc, [$r['code'], CodeGenerator::display($r['code']), $c['coupon_ref'], $c['label'], $offer, $validTo, $r['status'], $r['batch_ref'] ?? '']));
        }
        if ($rows !== []) {
            $ids = implode(',', array_map(static fn ($r) => (int) $r['id'], $rows));
            $this->db()->query("UPDATE engage_coupon_codes SET exported_at = NOW(), export_count = export_count + 1, updated_at = NOW() WHERE id IN ({$ids})");
        }
        $this->audit('coupon.codes_exported', $id, ['coupon_ref' => $c['coupon_ref'], 'count' => count($rows), 'status' => $status,
            'batch_ref' => $q['batch_ref'] ?? null]);

        return ['status' => 200, 'csv' => implode("\r\n", $lines) . "\r\n", 'filename' => strtolower($c['coupon_ref']) . '-codes.csv', 'count' => count($rows)];
    }

    public function voidCode(int $id, int $codeId, array $staff): array
    {
        $c = $this->find($id);
        $code = $this->db()->table('engage_coupon_codes')->where('id', $codeId)->where('coupon_id', $id)->get()->getRowArray();
        if (! $c || ! $code) {
            return ['status' => 404, 'error' => 'Code not found.'];
        }
        if ($code['status'] !== 'available') {
            return self::conflict('invalid_transition', 'Only an available code can be voided.');
        }
        $this->db()->query("UPDATE engage_coupon_codes SET status = 'void', updated_at = NOW() WHERE id = ? AND status = 'available'", [$codeId]);
        $this->audit('coupon.code_voided', $id, ['coupon_ref' => $c['coupon_ref'], 'code_id' => $codeId]);

        return ['status' => 200, 'data' => ['voided' => true]];
    }

    // ------------------------------------------------------------------ reports

    /**
     * Redemptions report: totals (list, discount, net) per coupon, per seller and overall, for committed redemptions in
     * [from, to) (committed_at), plus counts of reserved / released / reversed.
     */
    public function report(array $q = []): array
    {
        $where = ['1=1'];
        $binds = [];
        $from = S::parseInstant($q['from'] ?? null);
        $to = isset($q['to']) && $q['to'] !== '' ? self::validTo($q['to']) : null;
        if ($from !== null) {
            $where[] = 'r.created_at >= ?';
            $binds[] = $from;
        }
        if ($to !== null) {
            $where[] = 'r.created_at < ?';
            $binds[] = $to;
        }
        if (! empty($q['coupon_id']) && ctype_digit((string) $q['coupon_id'])) {
            $where[] = 'r.coupon_id = ?';
            $binds[] = (int) $q['coupon_id'];
        }
        if (! empty($q['seller_id']) && ctype_digit((string) $q['seller_id'])) {
            $where[] = 'r.seller_id = ?';
            $binds[] = (int) $q['seller_id'];
        }
        $w = implode(' AND ', $where);
        $agg = "COUNT(*) FILTER (WHERE r.state = 'committed') AS committed,
                COUNT(*) FILTER (WHERE r.state = 'reserved' AND r.reserved_until > NOW()) AS reserved,
                COUNT(*) FILTER (WHERE r.state = 'released' OR (r.state = 'reserved' AND r.reserved_until <= NOW())) AS released,
                COUNT(*) FILTER (WHERE r.state = 'reversed') AS reversed,
                COALESCE(SUM(r.list_paise) FILTER (WHERE r.state = 'committed'), 0) AS list_paise,
                COALESCE(SUM(r.discount_paise) FILTER (WHERE r.state = 'committed'), 0) AS discount_paise,
                COALESCE(SUM(r.net_paise) FILTER (WHERE r.state = 'committed'), 0) AS net_paise,
                COALESCE(SUM(r.taxable_paise) FILTER (WHERE r.state = 'committed'), 0) AS taxable_paise";
        $num = static function (array $r): array {
            foreach (['committed', 'reserved', 'released', 'reversed', 'list_paise', 'discount_paise', 'net_paise', 'taxable_paise'] as $k) {
                $r[$k] = (int) $r[$k];
            }

            return $r;
        };
        $db = $this->db();
        $byCoupon = array_map($num, $db->query("SELECT c.id AS coupon_id, c.coupon_ref, c.label, {$agg}
            FROM engage_coupon_redemptions r JOIN engage_coupons c ON c.id = r.coupon_id WHERE {$w}
            GROUP BY c.id, c.coupon_ref, c.label ORDER BY c.id DESC", $binds)->getResultArray());
        $bySeller = array_map($num, $db->query("SELECT r.seller_id, s.seller_code, {$agg}
            FROM engage_coupon_redemptions r LEFT JOIN engage_sellers s ON s.id = r.seller_id WHERE {$w}
            GROUP BY r.seller_id, s.seller_code ORDER BY s.seller_code NULLS LAST", $binds)->getResultArray());
        $totals = $num($db->query("SELECT {$agg} FROM engage_coupon_redemptions r WHERE {$w}", $binds)->getRowArray());

        return ['by_coupon' => $byCoupon, 'by_seller' => $bySeller, 'totals' => $totals];
    }

    // ------------------------------------------------------------------ audit

    public function audit(string $event, int $couponId, array $meta, bool $system = false): void
    {
        $opts = ['subject_kind' => 'coupon', 'subject_id' => $couponId, 'metadata' => $meta, 'fanout_console' => true];
        if ($system) {
            $opts += ['actor_email' => 'system:engage', 'actor_role' => 'system'];
        }
        Services::auditService()->log($event, $opts);
    }
}

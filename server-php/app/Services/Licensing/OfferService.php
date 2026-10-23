<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Libraries\Licensing\ManageAppRegistry;
use App\Libraries\Seller\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * The sellable catalogue (engage_offers). Nothing is shipped: every offer, price and term is typed by staff.
 *
 * Versioned per offer_code: draft → published → retired. Only a draft can be edited or discarded; a published
 * (or retired) version is immutable — the DB trigger engage_offers_guard refuses any change — so a change is a
 * new version (POST new-version → edit → publish). Publishing a version retires the previous published version of
 * the same offer_code in the same transaction, so at most one version per code is on sale. Licences keep the exact
 * version they were issued from (offer_code + offer_version + snapshot).
 *
 * Money is integer paise; gst_rate, price_basis (inclusive | exclusive), sac and the term are per offer (data,
 * never constants). Currency is INR (contract v1).
 *
 * Default offer (contract §3.1, D-L1): `is_default` marks the product's Basic plan — complimentary licence type,
 * price 0, no term end. At most one PUBLISHED default per product_code (unique index uq_engage_offers_default);
 * staff toggle it (setDefault) on a draft or a published version. Provisioning issues it to every signed-up holder.
 *
 * Publish-time checks against the offer's consumers (hazard G17): every app key must be a Manage registry key
 * (ManageAppRegistry; aliases such as drive → docs are folded when the draft is saved), and a priced offer needs a
 * Finance-valid SAC (99xxxx).
 */
class OfferService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    private const SELECT = 'SELECT o.*, to_jsonb(o.app_keys) AS app_keys_json, p.name AS product_label, t.label AS licence_type_label,
            t.lifecycle_kind, pl.name AS plan_label
        FROM engage_offers o
        JOIN engage_products p ON p.code = o.product_code
        JOIN engage_licence_types t ON t.code = o.licence_type_code
        LEFT JOIN engage_plans pl ON pl.code = o.plan_code';

    public static function view(array $o): array
    {
        $display = S::json($o['display']);

        return [
            'id'           => (int) $o['id'],
            'offer_code'   => $o['offer_code'],
            'version'      => (int) $o['version'],
            'status'       => $o['status'],
            'label'        => $o['label'],
            'description'  => $o['description'],
            'product'      => ['code' => $o['product_code'], 'label' => $o['product_label'] ?? $o['product_code']],
            'licence_type' => ['code' => $o['licence_type_code'], 'label' => $o['licence_type_label'] ?? $o['licence_type_code'], 'lifecycle_kind' => $o['lifecycle_kind'] ?? null],
            'plan'         => $o['plan_code'] !== null ? ['code' => $o['plan_code'], 'label' => $o['plan_label'] ?? $o['plan_code']] : null,
            'price_paise'  => (int) $o['price_paise'],
            'currency'     => $o['currency'],
            'price_basis'  => $o['price_basis'],
            'gst_rate'     => self::rate((string) $o['gst_rate']),
            'sac'          => $o['sac'],
            'term_months'  => (int) $o['term_months'],
            'term_days'    => (int) $o['term_days'],
            'seats'        => $o['seats'] !== null ? (int) $o['seats'] : null,
            'app_keys'     => S::fromJsonArray($o['app_keys_json'] ?? '[]'),
            'is_default'   => in_array($o['is_default'] ?? false, [true, 't', 'true', 1, '1'], true),
            'entitlements' => S::json($o['entitlements']),
            'display'      => (object) $display,
            'valid_from'   => S::iso($o['valid_from']),
            'valid_to'     => S::iso($o['valid_to']),
            'published_at' => S::iso($o['published_at']),
            'retired_at'   => S::iso($o['retired_at']),
            'created_at'   => S::iso($o['created_at']),
            'updated_at'   => S::iso($o['updated_at']),
        ];
    }

    /** "18.00" → "18", "12.50" → "12.5" (a string, never a float). */
    public static function rate(string $r): string
    {
        return str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r;
    }

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $where = [];
        $binds = [];
        if (! empty($q['status'])) {
            $where[] = 'o.status = ?';
            $binds[] = (string) $q['status'];
        }
        if (! empty($q['offer_code'])) {
            $where[] = 'o.offer_code = ?';
            $binds[] = (string) $q['offer_code'];
        }
        if (! empty($q['product_code'])) {
            $where[] = 'o.product_code = ?';
            $binds[] = (string) $q['product_code'];
        }
        $sql = self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.offer_code, o.version DESC';

        return array_map([self::class, 'view'], $this->db()->query($sql, $binds)->getResultArray());
    }

    public function findRow(int $id): ?array
    {
        return $this->db()->query(self::SELECT . ' WHERE o.id = ?', [$id])->getRowArray() ?: null;
    }

    public function findVersion(string $code, int $version): ?array
    {
        return $this->db()->query(self::SELECT . ' WHERE o.offer_code = ? AND o.version = ?', [$code, $version])->getRowArray() ?: null;
    }

    public function publishedVersion(string $code): ?array
    {
        return $this->db()->query(self::SELECT . " WHERE o.offer_code = ? AND o.status = 'published'", [$code])->getRowArray() ?: null;
    }

    /** Published offers on sale now (valid_from ≤ now < valid_to), for the catalogue. @return list<array<string,mixed>> */
    public function onSale(): array
    {
        return $this->db()->query(self::SELECT . " WHERE o.status = 'published' AND t.active
            AND (o.valid_from IS NULL OR o.valid_from <= NOW()) AND (o.valid_to IS NULL OR o.valid_to > NOW())
            ORDER BY o.product_code, o.offer_code")->getResultArray();
    }

    /** True when a published offer row is on sale right now. */
    public static function isOnSale(array $o): bool
    {
        $now = time();

        return $o['status'] === 'published'
            && ($o['valid_from'] === null || strtotime((string) $o['valid_from']) <= $now)
            && ($o['valid_to'] === null || strtotime((string) $o['valid_to']) > $now);
    }

    // ------------------------------------------------------------------ validation

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function fields(array $in, bool $creating, ?array $current = null): array
    {
        $e = [];
        $f = [];
        $has = static fn (string $k): bool => $creating || array_key_exists($k, $in);

        if ($creating) {
            $code = strtolower(trim((string) ($in['offer_code'] ?? '')));
            if (! preg_match('/\A[a-z0-9][a-z0-9_\-]{1,63}\z/', $code)) {
                $e['offer_code'] = 'offer_code: 2–64 lowercase letters, digits, - or _.';
            }
            $f['offer_code'] = $code;
        }
        if ($has('product_code')) {
            $pc = trim((string) ($in['product_code'] ?? ''));
            if ($pc === '' || $this->db()->table('engage_products')->where('code', $pc)->countAllResults() === 0) {
                $e['product_code'] = 'Choose a product from the Products catalogue.';
            }
            $f['product_code'] = $pc;
        }
        if ($has('licence_type_code')) {
            $tc = trim((string) ($in['licence_type_code'] ?? ''));
            $t = $tc === '' ? null : $this->db()->table('engage_licence_types')->where('code', $tc)->get()->getRowArray();
            if (! $t) {
                $e['licence_type_code'] = 'Choose a licence type (Licensing → Licence types).';
            } elseif (! in_array($t['active'], [true, 't', 'true', 1, '1'], true)) {
                $e['licence_type_code'] = 'That licence type is inactive.';
            }
            $f['licence_type_code'] = $tc;
        }
        if (array_key_exists('plan_code', $in)) {
            $plan = $in['plan_code'];
            if ($plan === null || $plan === '') {
                $f['plan_code'] = null;
            } elseif ($this->db()->table('engage_plans')->where('code', (string) $plan)->countAllResults() === 0) {
                $e['plan_code'] = 'Unknown plan code (Plans catalogue).';
            } else {
                $f['plan_code'] = (string) $plan;
            }
        }
        if ($has('label')) {
            $label = trim((string) ($in['label'] ?? ''));
            if (mb_strlen($label) < 2 || mb_strlen($label) > 191) {
                $e['label'] = 'Give the offer a label (2–191 characters).';
            }
            $f['label'] = $label;
        }
        if (array_key_exists('description', $in)) {
            $f['description'] = trim((string) $in['description']) ?: null;
        }
        if ($has('price_paise')) {
            $p = $in['price_paise'] ?? null;
            if (is_string($p) && preg_match('/\A\d{1,15}\z/', $p)) {
                $p = (int) $p;
            }
            if (! is_int($p) || $p < 0) {
                $e['price_paise'] = 'price_paise must be a whole number of paise (0 or more).';
            }
            $f['price_paise'] = is_int($p) ? $p : 0;
        }
        if ($has('currency') && array_key_exists('currency', $in)) {
            if ((string) $in['currency'] !== 'INR') {
                $e['currency'] = 'Only INR is supported.';
            }
            $f['currency'] = 'INR';
        }
        if ($has('price_basis')) {
            $b = (string) ($in['price_basis'] ?? '');
            if (! in_array($b, ['inclusive', 'exclusive'], true)) {
                $e['price_basis'] = 'price_basis must be inclusive or exclusive (of GST).';
            }
            $f['price_basis'] = $b;
        }
        if ($has('gst_rate')) {
            $r = $in['gst_rate'] ?? null;
            $rs = is_int($r) || is_float($r) ? (string) $r : (is_string($r) ? trim($r) : '');
            if (! preg_match('/\A\d{1,3}(\.\d{1,2})?\z/', $rs) || (float) $rs > 100) {
                $e['gst_rate'] = 'gst_rate must be a percentage from 0 to 100 (up to 2 decimals).';
            }
            $f['gst_rate'] = $rs === '' ? '0' : $rs;
        }
        if (array_key_exists('sac', $in)) {
            $sac = trim((string) ($in['sac'] ?? ''));
            if ($sac !== '' && ! preg_match('/\A\d{4,8}\z/', $sac)) {
                $e['sac'] = 'SAC must be 4–8 digits.';
            }
            $f['sac'] = $sac === '' ? null : $sac;
        }
        foreach (['term_months' => 120, 'term_days' => 3660] as $k => $max) {
            if ($has($k)) {
                $v = $in[$k] ?? 0;
                if (! is_numeric($v) || (int) $v != $v || (int) $v < 0 || (int) $v > $max) {
                    $e[$k] = "{$k} must be a whole number from 0 to {$max}.";
                }
                $f[$k] = (int) $v;
            }
        }
        if (array_key_exists('seats', $in)) {
            $s = $in['seats'];
            if ($s === null || $s === '') {
                $f['seats'] = null;
            } elseif (! is_numeric($s) || (int) $s != $s || (int) $s < 1) {
                $e['seats'] = 'seats must be 1 or more (leave empty for no seat limit).';
            } else {
                $f['seats'] = (int) $s;
            }
        }
        if (array_key_exists('app_keys', $in) || $creating) {
            $keys = $in['app_keys'] ?? null;
            if (is_string($keys)) {
                $keys = array_filter(array_map('trim', explode(',', $keys)), 'strlen');
            }
            if ($keys === null || $keys === []) {
                $keys = isset($f['product_code']) && $f['product_code'] !== '' ? [$f['product_code']] : ($current['product_code'] ?? null ? [$current['product_code']] : []);
            }
            if (! is_array($keys)) {
                $e['app_keys'] = 'app_keys must be a list of app keys.';
                $keys = [];
            }
            // Manage's spelling where Manage knows the key (drive → docs); unknown keys are kept so publish names them.
            $keys = array_values(array_unique(array_map(static fn ($k) => ManageAppRegistry::fold((string) $k), $keys)));
            foreach ($keys as $k) {
                if (! preg_match('/\A[a-z0-9][a-z0-9_\-]{1,47}\z/', $k)) {
                    $e['app_keys'] = 'Each app key: 2–48 lowercase letters, digits, - or _.';
                }
            }
            $f['app_keys'] = $keys;
        }
        if (array_key_exists('is_default', $in)) {
            $f['is_default'] = filter_var($in['is_default'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($has('entitlements')) {
            [$ent, $ee] = self::normaliseEntitlements($in['entitlements'] ?? []);
            if ($ee !== null) {
                $e['entitlements'] = $ee;
            }
            $f['entitlements'] = json_encode($ent);
        }
        if ($has('display')) {
            $d = $in['display'] ?? [];
            if (is_string($d)) {
                $d = $d === '' ? [] : json_decode($d, true);
            }
            if (! is_array($d) || ($d !== [] && array_is_list($d))) {
                $e['display'] = 'display must be a JSON object.';
                $d = [];
            } else {
                if (isset($d['attributes'])) {
                    $ok = is_array($d['attributes']) && array_is_list($d['attributes']);
                    foreach ($ok ? $d['attributes'] : [] as $a) {
                        $ok = $ok && is_array($a) && is_string($a['label'] ?? null) && (is_scalar($a['value'] ?? null));
                    }
                    if (! $ok) {
                        $e['display'] = 'display.attributes must be a list of {label, value}.';
                    }
                }
                if (isset($d['cycle_label']) && ! is_string($d['cycle_label'])) {
                    $e['display'] = 'display.cycle_label must be text.';
                }
                if (strlen((string) json_encode($d)) > 16384) {
                    $e['display'] = 'display is too large (16 KB maximum).';
                }
            }
            $f['display'] = json_encode((object) $d);
        }
        foreach (['valid_from', 'valid_to'] as $k) {
            if (array_key_exists($k, $in)) {
                if ($in[$k] === null || $in[$k] === '') {
                    $f[$k] = null;
                } else {
                    $v = S::parseInstant($in[$k]);
                    if ($v === null) {
                        $e[$k] = "{$k} must be a date (YYYY-MM-DD) or an ISO-8601 time.";
                    }
                    $f[$k] = $v;
                }
            }
        }
        $from = array_key_exists('valid_from', $f) ? $f['valid_from'] : ($current['valid_from'] ?? null);
        $to = array_key_exists('valid_to', $f) ? $f['valid_to'] : ($current['valid_to'] ?? null);
        if ($from && $to && strtotime($to) <= strtotime($from)) {
            $e['valid_to'] = 'valid_to must be after valid_from.';
        }

        return [$f, $e];
    }

    /** @return array{0: list<array<string,mixed>>, 1: ?string} */
    public static function normaliseEntitlements(mixed $in): array
    {
        if (is_string($in)) {
            $in = $in === '' ? [] : json_decode($in, true);
        }
        if (! is_array($in) || ($in !== [] && ! array_is_list($in))) {
            return [[], 'entitlements must be a list of {code, label, value, unit}.'];
        }
        $out = [];
        $codes = [];
        foreach ($in as $i => $x) {
            if (! is_array($x)) {
                return [[], "entitlements[{$i}] must be an object."];
            }
            $code = strtolower(trim((string) ($x['code'] ?? '')));
            $label = trim((string) ($x['label'] ?? ''));
            $value = $x['value'] ?? null;
            $unit = $x['unit'] ?? null;
            if (! preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $code)) {
                return [[], "entitlements[{$i}].code: 1–64 lowercase letters, digits, . - or _."];
            }
            if (isset($codes[$code])) {
                return [[], "entitlements[{$i}].code {$code} is repeated."];
            }
            if ($label === '' || mb_strlen($label) > 120) {
                return [[], "entitlements[{$i}].label is required (≤ 120 characters)."];
            }
            if ($value !== null && ! is_scalar($value)) {
                return [[], "entitlements[{$i}].value must be text, a number, true/false or empty."];
            }
            if ($unit !== null && (! is_string($unit) || mb_strlen($unit) > 32)) {
                return [[], "entitlements[{$i}].unit must be short text or empty."];
            }
            $codes[$code] = true;
            $out[] = ['code' => $code, 'label' => $label, 'value' => $value, 'unit' => $unit === '' ? null : $unit];
        }

        return [$out, null];
    }

    // ------------------------------------------------------------------ lifecycle

    public function createDraft(array $in, array $staff): array
    {
        [$f, $e] = $this->fields($in, true);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the offer.', 'details' => $e];
        }
        if ($this->db()->table('engage_offers')->where('offer_code', $f['offer_code'])->countAllResults() > 0) {
            return ['status' => 409, 'error' => "Offer {$f['offer_code']} already exists — create a new version of it instead."];
        }
        $id = $this->insert($f + ['version' => 1, 'status' => 'draft', 'created_by' => (int) $staff['id']]);
        $this->audit('offer.created', $id, ['offer_code' => $f['offer_code'], 'version' => 1]);

        return ['status' => 201, 'data' => self::view($this->findRow($id))];
    }

    private function insert(array $f): int
    {
        $keys = $f['app_keys'] ?? [];
        unset($f['app_keys']);
        $cols = array_keys($f);
        $sql = 'INSERT INTO engage_offers (' . implode(', ', $cols) . ', app_keys) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?::text[]) RETURNING id';

        return (int) $this->db()->query($sql, array_merge(array_values($f), [S::pgArray($keys)]))->getRow()->id;
    }

    public function updateDraft(int $id, array $in, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'draft') {
            return ['status' => 409, 'code' => 'offer_immutable', 'error' => 'Published and retired versions cannot change. Create a new version.'];
        }
        if (array_key_exists('offer_code', $in) && (string) $in['offer_code'] !== $o['offer_code']) {
            return ['status' => 422, 'error' => 'offer_code cannot change.', 'details' => ['offer_code' => 'Create a new offer instead.']];
        }
        [$f, $e] = $this->fields($in, false, $o);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the offer.', 'details' => $e];
        }
        if ($f !== []) {
            $sets = [];
            $binds = [];
            foreach ($f as $k => $v) {
                $sets[] = $k === 'app_keys' ? 'app_keys = ?::text[]' : "{$k} = ?";
                $binds[] = $k === 'app_keys' ? S::pgArray($v) : $v;
            }
            $binds[] = $id;
            $this->db()->query('UPDATE engage_offers SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?', $binds);
        }
        $this->audit('offer.updated', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'fields' => array_keys($f)]);

        return ['status' => 200, 'data' => self::view($this->findRow($id))];
    }

    public function discardDraft(int $id, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'draft') {
            return ['status' => 409, 'code' => 'offer_immutable', 'error' => 'Only a draft can be discarded. Retire a published version instead.'];
        }
        $this->db()->table('engage_offers')->where('id', $id)->delete();
        $this->audit('offer.discarded', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version']]);

        return ['status' => 200, 'data' => ['id' => $id, 'discarded' => true]];
    }

    public function newVersion(int $id, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        $draft = $this->db()->query("SELECT id, version FROM engage_offers WHERE offer_code = ? AND status = 'draft' LIMIT 1", [$o['offer_code']])->getRow();
        if ($draft) {
            return ['status' => 409, 'error' => "Offer {$o['offer_code']} already has a draft (v{$draft->version}). Edit that one."];
        }
        $max = (int) $this->db()->query('SELECT MAX(version) AS v FROM engage_offers WHERE offer_code = ?', [$o['offer_code']])->getRow()->v;
        $copy = [];
        foreach (['offer_code', 'product_code', 'licence_type_code', 'plan_code', 'label', 'description', 'price_paise', 'currency', 'price_basis',
            'gst_rate', 'sac', 'term_months', 'term_days', 'seats', 'entitlements', 'display', 'valid_from', 'valid_to', 'is_default'] as $k) {
            $copy[$k] = $o[$k];
        }
        $copy['is_default'] = in_array($copy['is_default'], [true, 't', 'true', 1, '1'], true);
        $copy['app_keys'] = S::fromJsonArray($o['app_keys_json']);
        // A new version starts on sale when published unless staff set new dates.
        $copy['valid_from'] = null;
        $copy['valid_to'] = null;
        $nid = $this->insert($copy + ['version' => $max + 1, 'status' => 'draft', 'created_by' => (int) $staff['id']]);
        $this->audit('offer.version_created', $nid, ['offer_code' => $o['offer_code'], 'version' => $max + 1, 'from_offer_id' => $id]);

        return ['status' => 201, 'data' => self::view($this->findRow($nid))];
    }

    public function publish(int $id, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'draft') {
            return ['status' => 409, 'error' => 'Only a draft can be published.'];
        }
        $type = (new LicenceTypeService($this->db()))->findByCode($o['licence_type_code']);
        if (! $type || ! in_array($type['active'], [true, 't', 'true', 1, '1'], true)) {
            return ['status' => 422, 'error' => 'The licence type of this offer is inactive.', 'details' => ['licence_type_code' => 'Activate it or choose another type.']];
        }
        if ((int) $o['price_paise'] > 0 && ($o['sac'] === null || $o['sac'] === '')) {
            return ['status' => 422, 'error' => 'A priced offer needs a SAC code before it is published (Finance needs it on the invoice).', 'details' => ['sac' => 'Required for a priced offer.']];
        }
        if ((int) $o['price_paise'] > 0 && ! self::financeSac((string) $o['sac'])) {
            return ['status' => 422, 'error' => 'Finance invoices need a 6-digit services SAC starting 99 (e.g. 997331).', 'details' => ['sac' => 'Must be 99 followed by 4 digits for a priced offer.']];
        }
        if (S::fromJsonArray($o['app_keys_json']) === []) {
            return ['status' => 422, 'error' => 'Name at least one app this offer licenses.', 'details' => ['app_keys' => 'Required.']];
        }
        [, $unknown] = ManageAppRegistry::normalizeList(S::fromJsonArray($o['app_keys_json']));
        if ($unknown !== []) {
            return ['status' => 422, 'error' => 'Manage does not know the app key(s) ' . implode(', ', $unknown) . '. Use Manage\'s keys (e.g. books, inventory, billing, notes, docs).',
                'details' => ['app_keys' => 'Unknown to Manage: ' . implode(', ', $unknown) . '.']];
        }
        $isDefault = in_array($o['is_default'] ?? false, [true, 't', 'true', 1, '1'], true);
        if ($isDefault && ($de = $this->defaultOfferProblem($o, $type)) !== null) {
            return ['status' => 422, 'error' => $de, 'details' => ['is_default' => $de]];
        }
        if ($o['valid_to'] !== null && strtotime((string) $o['valid_to']) <= time()) {
            return ['status' => 422, 'error' => 'valid_to has already passed.', 'details' => ['valid_to' => 'Must be in the future.']];
        }
        $db = $this->db();
        $db->transException(true)->transBegin();
        try {
            $prev = $db->query("SELECT id, version FROM engage_offers WHERE offer_code = ? AND status = 'published' FOR UPDATE", [$o['offer_code']])->getRowArray();
            if ($prev) {
                $db->query("UPDATE engage_offers SET status = 'retired', retired_at = NOW(), retired_by = ?, updated_at = NOW() WHERE id = ?", [(int) $staff['id'], (int) $prev['id']]);
            }
            $db->query("UPDATE engage_offers SET status = 'published', published_at = NOW(), published_by = ?, updated_at = NOW() WHERE id = ? AND status = 'draft'", [(int) $staff['id'], $id]);
            $db->transCommit();
            $db->transException(false);
        } catch (\Throwable $e) {
            $db->transRollback();
            $db->transException(false);
            $db->resetTransStatus();
            if (str_contains($e->getMessage(), 'uq_engage_offers_default')) {
                return ['status' => 409, 'code' => 'default_offer_exists', 'error' => "Another offer is already the published Basic (default) of {$o['product_code']}. Clear its default flag or retire it first."];
            }
            log_message('error', 'Offer publish failed: ' . $e->getMessage());

            return ['status' => 500, 'error' => 'Could not publish the offer.'];
        }
        $this->audit('offer.published', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'retired_offer_id' => $prev['id'] ?? null]);

        return ['status' => 200, 'data' => self::view($this->findRow($id))];
    }

    public function retire(int $id, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'published') {
            return ['status' => 409, 'error' => $o['status'] === 'draft' ? 'A draft is not on sale — discard it instead.' : 'This version is already retired.'];
        }
        $this->db()->query("UPDATE engage_offers SET status = 'retired', retired_at = NOW(), retired_by = ?, updated_at = NOW() WHERE id = ?", [(int) $staff['id'], $id]);
        $this->audit('offer.retired', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version']]);

        return ['status' => 200, 'data' => self::view($this->findRow($id))];
    }

    /**
     * Toggle the default (Basic) flag on a draft or published version. At most one published default per product —
     * enforced by uq_engage_offers_default (409 default_offer_exists).
     */
    public function setDefault(int $id, bool $isDefault, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] === 'retired') {
            return ['status' => 409, 'error' => 'A retired version is no longer on sale; set the flag on the current version.'];
        }
        if ($isDefault) {
            $type = (new LicenceTypeService($this->db()))->findByCode($o['licence_type_code']);
            if (($de = $this->defaultOfferProblem($o, $type)) !== null) {
                return ['status' => 422, 'error' => $de, 'details' => ['is_default' => $de]];
            }
        }
        try {
            $this->db()->query('UPDATE engage_offers SET is_default = ?, updated_at = NOW() WHERE id = ?', [$isDefault, $id]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'uq_engage_offers_default')) {
                return ['status' => 409, 'code' => 'default_offer_exists', 'error' => "Another offer is already the published Basic (default) of {$o['product_code']}. Clear its default flag first."];
            }
            throw $e;
        }
        $this->audit($isDefault ? 'offer.default_set' : 'offer.default_cleared', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'product_code' => $o['product_code']]);

        return ['status' => 200, 'data' => self::view($this->findRow($id))];
    }

    /** Why this offer cannot be a product's Basic (D-L1: complimentary, free, no end), or null. */
    private function defaultOfferProblem(array $o, ?array $type): ?string
    {
        if ((int) $o['price_paise'] !== 0) {
            return 'The Basic (default) offer must be free (price 0).';
        }
        if ((int) $o['term_months'] !== 0 || (int) $o['term_days'] !== 0) {
            return 'The Basic (default) offer has no term end (term 0 months + 0 days).';
        }
        if (! $type || $type['lifecycle_kind'] !== 'complimentary') {
            return 'The Basic (default) offer uses a complimentary licence type.';
        }

        return null;
    }

    /** A SAC Finance accepts on an invoice line: 99 followed by 4 digits. */
    public static function financeSac(string $sac): bool
    {
        return preg_match('/\A99\d{4}\z/', $sac) === 1;
    }

    /** The product's published default (Basic) offer that is on sale with an active type, or null. */
    public function defaultFor(string $productCode): ?array
    {
        return $this->db()->query(self::SELECT . " WHERE o.product_code = ? AND o.is_default AND o.status = 'published' AND t.active
            AND (o.valid_from IS NULL OR o.valid_from <= NOW()) AND (o.valid_to IS NULL OR o.valid_to > NOW())", [$productCode])->getRowArray() ?: null;
    }

    /** Every product's published default offer on sale. @return list<array<string,mixed>> */
    public function defaultOffers(): array
    {
        return $this->db()->query(self::SELECT . " WHERE o.is_default AND o.status = 'published' AND t.active
            AND (o.valid_from IS NULL OR o.valid_from <= NOW()) AND (o.valid_to IS NULL OR o.valid_to > NOW()) ORDER BY o.product_code")->getResultArray();
    }

    /** Immutable copy kept on a licence (what was sold, even if labels change later). */
    public static function snapshot(array $o): array
    {
        $v = self::view($o);
        unset($v['id'], $v['status'], $v['published_at'], $v['retired_at'], $v['created_at'], $v['updated_at']);
        $v['offer_id'] = (int) $o['id'];

        return $v;
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'offer', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}

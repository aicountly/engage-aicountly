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
 * Versioned per offer_code: draft → published → retired, or draft → scheduled → published (a price revision with an
 * effective date, "with effect from"). Only a draft can be edited or discarded; a scheduled, published or retired
 * version is immutable — the DB trigger engage_offers_guard refuses any change — so a change is a new version
 * (POST new-version → edit → publish, or POST revise for a price). Publishing a version whose valid_from is still ahead
 * SCHEDULES it: the version on sale now stays on sale until that instant, and from then on the scheduled version is the
 * one on sale — every read here resolves that by time (onSaleSql), and engage:offers-activate records it (activateDue).
 * Publishing with no future date retires the previous published version in the same transaction. So exactly one
 * version per code is on sale at any instant. At most one revision per code is pending (uq_engage_offers_scheduled).
 * Licences keep the exact version they were issued from (offer_code + offer_version + snapshot).
 *
 * Channels (docs/SELLING_LICENCES.md §5): where an offer is sold — website, manage, seller. Visibility, not terms, so
 * they can change on a published version (setChannels). Staff can always issue any offer on sale.
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

    public const CHANNELS = ['website', 'manage', 'seller'];

    /** What a licence of an offer covers: every company the customer owns, or one company at a time (docs/LICENSING.md §3.6). */
    public const SCOPES = ['workspace', 'company'];

    private const SELECT = "SELECT o.*, to_jsonb(o.app_keys) AS app_keys_json, to_jsonb(o.channels) AS channels_json, p.name AS product_label,
            t.label AS licence_type_label, t.lifecycle_kind, pl.name AS plan_label,
            EXISTS (SELECT 1 FROM engage_offers n WHERE n.offer_code = o.offer_code AND n.status = 'scheduled'
                     AND n.version > o.version AND n.valid_from <= NOW()) AS superseded_now
        FROM engage_offers o
        JOIN engage_products p ON p.code = o.product_code
        JOIN engage_licence_types t ON t.code = o.licence_type_code
        LEFT JOIN engage_plans pl ON pl.code = o.plan_code";

    /**
     * SQL: offer alias $a is on sale now — published (or scheduled and its effective date has come), inside its
     * valid_from / valid_to window, and not replaced by a newer scheduled version that has taken effect. The one
     * definition every catalogue, issue, renewal and quote reads, so a price revision takes over at its effective
     * instant everywhere, before engage:offers-activate has recorded it. (Type activity and course exclusion are added
     * by the callers that need them.)
     */
    public static function onSaleSql(string $a = 'o'): string
    {
        return "({$a}.status IN ('published','scheduled')
            AND ({$a}.valid_from IS NULL OR {$a}.valid_from <= NOW()) AND ({$a}.valid_to IS NULL OR {$a}.valid_to > NOW())
            AND NOT EXISTS (SELECT 1 FROM engage_offers nx WHERE nx.offer_code = {$a}.offer_code AND nx.status = 'scheduled'
                             AND nx.version > {$a}.version AND nx.valid_from <= NOW()))";
    }

    /** SQL: offer alias $a is offered on $channel (one of CHANNELS; constants only). */
    public static function channelSql(string $a, string $channel): string
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Unknown channel ' . $channel);
        }

        return "'{$channel}' = ANY({$a}.channels)";
    }

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
            // workspace: every company the customer owns; company: one company at a time (docs/LICENSING.md §3.6).
            'licence_scope' => (string) ($o['licence_scope'] ?? 'workspace'),
            'app_keys'     => S::fromJsonArray($o['app_keys_json'] ?? '[]'),
            'is_default'   => in_array($o['is_default'] ?? false, [true, 't', 'true', 1, '1'], true),
            'entitlements' => S::json($o['entitlements']),
            'display'      => (object) $display,
            'valid_from'   => S::iso($o['valid_from']),
            'valid_to'     => S::iso($o['valid_to']),
            'channels'     => self::channelsOf($o),
            // When this version's price applies: its effective date (valid_from), else the moment it went on sale.
            'effective_from' => S::iso($o['valid_from'] ?? null) ?? S::iso($o['published_at'] ?? null),
            'on_sale_now'  => self::isOnSale($o),
            'scheduled_at' => S::iso($o['scheduled_at'] ?? null),
            'revision_note' => $o['revision_note'] ?? null,
            'published_at' => S::iso($o['published_at']),
            'retired_at'   => S::iso($o['retired_at']),
            'retired_reason' => $o['retired_reason'] ?? null,
            'created_at'   => S::iso($o['created_at']),
            'updated_at'   => S::iso($o['updated_at']),
        ];
    }

    /** @return list<string> the channels of an offer row (all three when the column is absent, as before channels). */
    public static function channelsOf(array $o): array
    {
        if (! array_key_exists('channels_json', $o) && ! array_key_exists('channels', $o)) {
            return self::CHANNELS;
        }
        $c = S::fromJsonArray($o['channels_json'] ?? null);
        if ($c === [] && is_string($o['channels'] ?? null)) {
            $c = array_values(array_filter(array_map('trim', explode(',', trim((string) $o['channels'], '{}'))), 'strlen'));
        }

        return array_values(array_intersect(self::CHANNELS, $c));
    }

    private static function bool(mixed $v): bool
    {
        return in_array($v, [true, 't', 'true', 1, '1'], true);
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

    /**
     * The version of $code in effect now: the newest published version, or a scheduled one whose effective date has
     * come (it replaces the published one at that instant, before engage:offers-activate records it). Callers still
     * check isOnSale() — the version in effect may be outside its own valid_from / valid_to window.
     */
    public function publishedVersion(string $code): ?array
    {
        return $this->db()->query(self::SELECT . " WHERE o.offer_code = ?
            AND (o.status = 'published' OR (o.status = 'scheduled' AND o.valid_from <= NOW()))
            ORDER BY o.version DESC LIMIT 1", [$code])->getRowArray() ?: null;
    }

    /** The revision of $code scheduled to take effect later (not yet in effect), or null. */
    public function scheduledVersion(string $code): ?array
    {
        return $this->db()->query(self::SELECT . " WHERE o.offer_code = ? AND o.status = 'scheduled' AND o.valid_from > NOW()", [$code])->getRowArray() ?: null;
    }

    /**
     * Offers on sale now (onSaleSql), active licence type, no course; optionally only those sold on $channel.
     *
     * @return list<array<string,mixed>>
     */
    public function onSale(?string $channel = null): array
    {
        return $this->db()->query(self::SELECT . ' WHERE ' . self::onSaleSql('o') . ' AND t.active
            AND ' . self::notCourseSql('o') . ($channel !== null ? ' AND ' . self::channelSql('o', $channel) : '') . '
            ORDER BY o.product_code, o.offer_code')->getResultArray();
    }

    /** Revisions scheduled to take effect later, keyed by offer_code. @return array<string, array<string,mixed>> */
    public function pendingRevisions(): array
    {
        $out = [];
        foreach ($this->db()->query(self::SELECT . " WHERE o.status = 'scheduled' AND o.valid_from > NOW()")->getResultArray() as $r) {
            $out[(string) $r['offer_code']] = $r;
        }

        return $out;
    }

    /** True when an offer row (from SELECT) is on sale right now — the PHP twin of onSaleSql(). */
    public static function isOnSale(array $o): bool
    {
        $now = time();

        return in_array($o['status'], ['published', 'scheduled'], true)
            && ! self::bool($o['superseded_now'] ?? false)
            && ! ManageAppRegistry::isCourse((string) ($o['product_code'] ?? ''))
            && ManageAppRegistry::courseKeysIn(S::fromJsonArray($o['app_keys_json'] ?? '[]')) === []
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
            $prod = $pc === '' ? null : $this->db()->table('engage_products')->select('name, category')->where('code', $pc)->get()->getRowArray();
            if (! $prod) {
                $e['product_code'] = 'Choose a product from the Products catalogue.';
            } elseif (self::isCourseProduct($pc, $prod['category'] ?? null)) {
                $e['product_code'] = ($prod['name'] ?: $pc) . ' is an Aicountly Academy course, not a licensable product: no offer can be made for it.';
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
        if (array_key_exists('licence_scope', $in) || $creating) {
            // Who the licence covers: every company the customer owns, or one company at a time (docs/LICENSING.md §3.6).
            $sc = strtolower(trim((string) ($in['licence_scope'] ?? 'workspace')));
            if (! in_array($sc, self::SCOPES, true)) {
                $e['licence_scope'] = 'licence_scope must be workspace (every company the customer owns) or company (one company).';
            }
            $f['licence_scope'] = $sc;
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
            if (($course = ManageAppRegistry::courseKeysIn($keys)) !== [] && ! isset($e['product_code'])) {
                $e['app_keys'] = implode(', ', $course) . ': Aicountly Academy course(s), not licensable — remove from app_keys.';
            }
            $f['app_keys'] = $keys;
        }
        if (array_key_exists('is_default', $in)) {
            $f['is_default'] = filter_var($in['is_default'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('channels', $in)) {
            [$ch, $ce] = self::normaliseChannels($in['channels']);
            if ($ce !== null) {
                $e['channels'] = $ce;
            }
            $f['channels'] = $ch;
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
        $isDefault = array_key_exists('is_default', $f) ? (bool) $f['is_default'] : self::bool($current['is_default'] ?? false);
        if ($isDefault && ($f['licence_scope'] ?? ($current['licence_scope'] ?? 'workspace')) !== 'workspace') {
            $e['licence_scope'] = 'A Basic (default) offer covers every company of the holder: it cannot be for one company.';
        }

        return [$f, $e];
    }

    /**
     * channels: a list (or comma text) drawn from CHANNELS; an empty list means "staff only". Canonical order.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    public static function normaliseChannels(mixed $in): array
    {
        if ($in === null) {
            return [self::CHANNELS, null];
        }
        if (is_string($in)) {
            $in = array_filter(array_map('trim', explode(',', $in)), 'strlen');
        }
        if (! is_array($in)) {
            return [[], 'channels must be a list of: ' . implode(', ', self::CHANNELS) . '.'];
        }
        $in = array_map(static fn ($c) => strtolower(trim((string) $c)), $in);
        $unknown = array_values(array_diff($in, self::CHANNELS));
        if ($unknown !== []) {
            return [[], 'Unknown channel(s): ' . implode(', ', $unknown) . '. Use ' . implode(', ', self::CHANNELS) . '.'];
        }

        return [array_values(array_intersect(self::CHANNELS, $in)), null];
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
            if (str_contains($code, '.') && ManageAppRegistry::isCourse(explode('.', $code)[0])) {
                return [[], "entitlements[{$i}].code {$code}: " . explode('.', $code)[0] . ' is an Aicountly Academy course, not licensable.'];
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
        $channels = $f['channels'] ?? self::CHANNELS;
        unset($f['app_keys'], $f['channels']);
        $cols = array_keys($f);
        $sql = 'INSERT INTO engage_offers (' . implode(', ', $cols) . ', app_keys, channels) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?::text[], ?::text[]) RETURNING id';

        return (int) $this->db()->query($sql, array_merge(array_values($f), [S::pgArray($keys), S::pgArray($channels)]))->getRow()->id;
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
        if (array_key_exists('licence_scope', $f) && $f['licence_scope'] !== ($o['licence_scope'] ?? 'workspace')) {
            // Licences of every version renew and change plan within one offer code: they must all cover the same.
            $other = $this->db()->query("SELECT version FROM engage_offers WHERE offer_code = ? AND id <> ? AND status <> 'draft' AND licence_scope <> ? ORDER BY version DESC LIMIT 1",
                [$o['offer_code'], $id, $f['licence_scope']])->getRow();
            if ($other) {
                return ['status' => 422, 'error' => 'Please correct the offer.', 'details' => ['licence_scope' => "v{$other->version} of {$o['offer_code']} covers "
                    . ($f['licence_scope'] === 'company' ? 'every company' : 'one company') . ': every version of an offer covers the same. Create a new offer for the other kind.']];
            }
        }
        if ($f !== []) {
            $sets = [];
            $binds = [];
            foreach ($f as $k => $v) {
                $array = in_array($k, ['app_keys', 'channels'], true);
                $sets[] = $array ? "{$k} = ?::text[]" : "{$k} = ?";
                $binds[] = $array ? S::pgArray($v) : $v;
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
        if (($ce = $this->courseProblem($o)) !== null) {
            return ['status' => 422, 'code' => 'course_not_licensable', 'error' => $ce, 'details' => ['product_code' => $ce]];
        }
        $max = (int) $this->db()->query('SELECT MAX(version) AS v FROM engage_offers WHERE offer_code = ?', [$o['offer_code']])->getRow()->v;
        $copy = [];
        foreach (['offer_code', 'product_code', 'licence_type_code', 'plan_code', 'label', 'description', 'price_paise', 'currency', 'price_basis',
            'gst_rate', 'sac', 'term_months', 'term_days', 'seats', 'licence_scope', 'entitlements', 'display', 'valid_from', 'valid_to', 'is_default'] as $k) {
            $copy[$k] = $o[$k];
        }
        $copy['is_default'] = in_array($copy['is_default'], [true, 't', 'true', 1, '1'], true);
        $copy['app_keys'] = S::fromJsonArray($o['app_keys_json']);
        $copy['channels'] = self::channelsOf($o);
        // A new version starts on sale when published unless staff set new dates (a future "on sale from" date makes
        // publishing schedule it as a revision with that effective date).
        $copy['valid_from'] = null;
        $copy['valid_to'] = null;
        $nid = $this->insert($copy + ['version' => $max + 1, 'status' => 'draft', 'created_by' => (int) $staff['id']]);
        $this->audit('offer.version_created', $nid, ['offer_code' => $o['offer_code'], 'version' => $max + 1, 'from_offer_id' => $id]);

        return ['status' => 201, 'data' => self::view($this->findRow($nid))];
    }

    /**
     * Publish a draft. When its valid_from (the effective date) is still ahead the draft is SCHEDULED instead: the
     * version on sale now stays on sale until that instant (a price revision "with effect from"). Otherwise it goes on
     * sale now and the previous published version of the code is retired in the same transaction. Refused while
     * another revision of the code is scheduled (409 revision_pending) — cancel it or let it take effect first.
     */
    public function publish(int $id, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'draft') {
            return ['status' => 409, 'error' => 'Only a draft can be published.'];
        }
        if (($problem = $this->publishProblem($o)) !== null) {
            return $problem;
        }
        $r = $this->guarded($o, fn () => $this->goLiveTx($o, $staff));
        if (($r['status'] ?? 500) !== 200) {
            return $r;
        }
        $scheduled = $r['data']['status'] === 'scheduled';
        $this->audit($scheduled ? 'offer.scheduled' : 'offer.published', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'],
            'retired_offer_id' => $r['retired_offer_id'] ?? null, 'effective_from' => $scheduled ? S::iso($o['valid_from']) : null]);

        return ['status' => 200, 'data' => $r['data']];
    }

    /**
     * Revise the price of the version in effect now, with an effective date (WEF): a new version that copies it with
     * the new price (and optionally price basis / GST rate), valid_from = the effective date, and the reason in
     * revision_note — published at once when the date is today, otherwise scheduled. One transaction: nothing is left
     * behind when a check fails.
     *
     * Body: {price_paise, price_basis?, gst_rate?, effective_from: "YYYY-MM-DD" (00:00 IST) | ISO-8601, note}
     */
    public function revise(int $id, array $in, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        $code = (string) $o['offer_code'];
        $current = $this->publishedVersion($code);
        if (! $current || (int) $current['id'] !== $id) {
            return ['status' => 409, 'code' => 'not_current', 'error' => 'Revise the version in effect now' . ($current ? " (v{$current['version']})" : '') . '.'];
        }
        $e = [];
        $price = $in['price_paise'] ?? null;
        if (is_string($price) && preg_match('/\A\d{1,15}\z/', $price)) {
            $price = (int) $price;
        }
        if (! is_int($price) || $price < 0) {
            $e['price_paise'] = 'The new price: a whole number of paise (0 or more).';
        }
        $basis = array_key_exists('price_basis', $in) && $in['price_basis'] !== null && $in['price_basis'] !== '' ? (string) $in['price_basis'] : (string) $o['price_basis'];
        if (! in_array($basis, ['inclusive', 'exclusive'], true)) {
            $e['price_basis'] = 'price_basis must be inclusive or exclusive (of GST).';
        }
        $rate = array_key_exists('gst_rate', $in) && $in['gst_rate'] !== null && $in['gst_rate'] !== '' ? $in['gst_rate'] : (string) $o['gst_rate'];
        $rate = is_int($rate) || is_float($rate) ? (string) $rate : trim((string) $rate);
        if (! preg_match('/\A\d{1,3}(\.\d{1,2})?\z/', $rate) || (float) $rate > 100) {
            $e['gst_rate'] = 'gst_rate must be a percentage from 0 to 100 (up to 2 decimals).';
        }
        $wef = S::parseInstant($in['effective_from'] ?? null);
        $todayIst = S::db(new \DateTimeImmutable('today', new \DateTimeZone(S::TZ)));
        if ($wef === null) {
            $e['effective_from'] = 'The date the new price takes effect: YYYY-MM-DD (from 00:00 IST that day) or an ISO-8601 time.';
        } elseif (strtotime($wef) < strtotime($todayIst)) {
            $e['effective_from'] = 'The effective date cannot be in the past (what was charged before today stands).';
        }
        $note = trim((string) ($in['note'] ?? $in['revision_note'] ?? ''));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
            $e['note'] = 'Say why the price changes (3–1000 characters); staff see it in the price history.';
        }
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the revision.', 'details' => $e];
        }
        if ($price === (int) $o['price_paise'] && $basis === $o['price_basis'] && self::rate($rate) === self::rate((string) $o['gst_rate'])) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'That is the current price.', 'details' => ['price_paise' => 'Unchanged.']];
        }
        // The effective instant: the date asked for, or now when that is today (the old price was on sale until now).
        $validFrom = strtotime((string) $wef) > time() ? (string) $wef : gmdate('Y-m-d H:i:s') . '+00';
        if ($o['valid_to'] !== null && strtotime((string) $o['valid_to']) <= strtotime($validFrom)) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'This offer stops being on sale (' . S::displayDate((string) $o['valid_to']) . ') before that date.',
                'details' => ['effective_from' => 'Before the offer\'s own end of sale.']];
        }
        if (($ce = $this->courseProblem($o)) !== null) {
            return ['status' => 422, 'code' => 'course_not_licensable', 'error' => $ce, 'details' => ['product_code' => $ce]];
        }

        $r = $this->guarded($o, function () use ($o, $code, $price, $basis, $rate, $validFrom, $note, $staff) {
            $db = $this->db();
            $db->query('SELECT id FROM engage_offers WHERE offer_code = ? FOR UPDATE', [$code]);
            $now = $this->publishedVersion($code);
            if (! $now || (int) $now['id'] !== (int) $o['id']) {
                return ['status' => 409, 'code' => 'not_current', 'error' => 'The price was changed by someone else meanwhile. Reload and revise the version in effect now.'];
            }
            $draft = $db->query("SELECT version FROM engage_offers WHERE offer_code = ? AND status = 'draft' LIMIT 1", [$code])->getRow();
            if ($draft) {
                return ['status' => 409, 'code' => 'draft_exists', 'error' => "Offer {$code} has a draft (v{$draft->version}). Publish or discard it before revising the price."];
            }
            $max = (int) $db->query('SELECT MAX(version) AS v FROM engage_offers WHERE offer_code = ?', [$code])->getRow()->v;
            $copy = [];
            foreach (['offer_code', 'product_code', 'licence_type_code', 'plan_code', 'label', 'description', 'currency', 'sac', 'term_months',
                'term_days', 'seats', 'licence_scope', 'entitlements', 'display', 'valid_to'] as $k) {
                $copy[$k] = $o[$k];
            }
            $copy += [
                'price_paise' => $price, 'price_basis' => $basis, 'gst_rate' => $rate, 'valid_from' => $validFrom, 'revision_note' => $note,
                'is_default' => self::bool($o['is_default'] ?? false), 'app_keys' => S::fromJsonArray($o['app_keys_json']),
                'channels' => self::channelsOf($o), 'version' => $max + 1, 'status' => 'draft', 'created_by' => (int) $staff['id'],
            ];
            $nid = $this->insert($copy);
            $n = $this->findRow($nid);
            if (($problem = $this->publishProblem($n)) !== null) {
                return $problem;
            }
            $live = $this->goLiveTx($n, $staff);

            return ($live['status'] ?? 500) === 200 ? $live + ['new_id' => $nid] : $live;
        });
        if (($r['status'] ?? 500) !== 200) {
            return $r;
        }
        $scheduled = $r['data']['status'] === 'scheduled';
        $this->audit('offer.revised', (int) $r['new_id'], ['offer_code' => $code, 'from_version' => (int) $o['version'], 'version' => (int) $r['data']['version'],
            'price_paise' => ['from' => (int) $o['price_paise'], 'to' => $price], 'price_basis' => ['from' => $o['price_basis'], 'to' => $basis],
            'gst_rate' => ['from' => self::rate((string) $o['gst_rate']), 'to' => self::rate($rate)], 'effective_from' => S::iso($validFrom),
            'scheduled' => $scheduled, 'note' => $note]);

        return ['status' => 201, 'data' => $r['data']];
    }

    /** Withdraw a scheduled revision before it takes effect (reason required): it is retired, never having been on sale. */
    public function cancelScheduled(int $id, string $reason, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'scheduled') {
            return ['status' => 409, 'code' => 'not_scheduled', 'error' => 'Only a scheduled revision can be cancelled.'];
        }
        if (strtotime((string) $o['valid_from']) <= time()) {
            return ['status' => 409, 'code' => 'already_in_effect', 'error' => 'This revision has already taken effect. Revise the price again instead.'];
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Say why the revision is withdrawn.', 'details' => ['reason' => 'Required.']];
        }
        $this->db()->query("UPDATE engage_offers SET status = 'retired', retired_at = NOW(), retired_by = ?, retired_reason = ?, updated_at = NOW()
            WHERE id = ? AND status = 'scheduled' AND valid_from > NOW()", [(int) $staff['id'], 'Withdrawn before it took effect: ' . mb_substr($reason, 0, 1000), $id]);
        if ($this->db()->affectedRows() !== 1) {
            return ['status' => 409, 'code' => 'already_in_effect', 'error' => 'This revision has just taken effect. Reload.'];
        }
        $this->audit('offer.revision_cancelled', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'reason' => $reason]);

        return ['status' => 200, 'data' => self::view($this->findRow($id))];
    }

    /**
     * Record every scheduled revision whose effective date has come (engage:offers-activate, every minute): the old
     * published version is retired AT the effective date and the scheduled one published from it. Reads never wait for
     * this (onSaleSql). Idempotent; one transaction per offer code.
     *
     * @return array{activated: list<array{offer_code:string, version:int, product_code:string, is_default:bool, replaced_version:?int}>, failed: list<array{offer_code:string, error:string}>}
     */
    public function activateDue(?string $code = null): array
    {
        $rows = $this->db()->query("SELECT id, offer_code FROM engage_offers WHERE status = 'scheduled' AND valid_from <= NOW()"
            . ($code !== null ? ' AND offer_code = ?' : '') . ' ORDER BY valid_from, id', $code !== null ? [$code] : [])->getResultArray();
        $done = [];
        $failed = [];
        foreach ($rows as $row) {
            try {
                $r = $this->tx(function () use ($row) {
                    $this->db()->query('SELECT id FROM engage_offers WHERE offer_code = ? FOR UPDATE', [(string) $row['offer_code']]);

                    return $this->promoteTx((int) $row['id']);
                });
            } catch (\Throwable $e) {
                log_message('error', 'Offer revision {c} could not take effect: {m}', ['c' => $row['offer_code'], 'm' => $e->getMessage()]);
                $failed[] = ['offer_code' => (string) $row['offer_code'], 'error' => $e->getMessage()];

                continue;
            }
            if (($r['data'] ?? null) !== null) {
                $done[] = $r['data'];
                Services::auditService()->log('offer.revision_effective', ['subject_kind' => 'offer', 'subject_id' => (int) $row['id'], 'metadata' => $r['data'],
                    'fanout_console' => true, 'actor_email' => 'system:engage', 'actor_role' => 'system']);
            }
        }

        return ['activated' => $done, 'failed' => $failed];
    }

    /**
     * Price history of an offer code: every version with the period its price applied (effective_from → effective_to),
     * the revision note and who scheduled / published / retired it. Newest first.
     *
     * @return array<string,mixed>|null
     */
    public function history(string $code): ?array
    {
        $rows = $this->db()->query(self::SELECT . ' WHERE o.offer_code = ? ORDER BY o.version', [$code])->getResultArray();
        if ($rows === []) {
            return null;
        }
        $versions = [];
        $nextFrom = null;
        foreach (array_reverse($rows) as $r) {
            $v = self::view($r);
            $wasOnSale = $r['published_at'] !== null || ($r['status'] === 'scheduled' && strtotime((string) $r['valid_from']) <= time());
            $to = null;
            if ($r['status'] !== 'draft' && $wasOnSale) {
                $candidates = array_filter([$r['retired_at'] ?? null, $r['valid_to'] ?? null, $nextFrom], static fn ($x) => $x !== null && $x !== '');
                $to = $candidates === [] ? null : S::iso((string) array_reduce($candidates, static fn ($m, $x) => $m === null || strtotime((string) $x) < strtotime((string) $m) ? $x : $m));
            }
            $v['effective_to'] = $to;
            $v['was_on_sale'] = $wasOnSale;
            $versions[] = $v;
            if ($wasOnSale || $r['status'] === 'scheduled') {
                $nextFrom = $r['valid_from'] ?? $r['published_at'];
            }
        }
        $current = $this->publishedVersion($code);
        $pending = $this->scheduledVersion($code);

        return [
            'offer_code' => $code,
            'current'    => $current ? ['id' => (int) $current['id'], 'version' => (int) $current['version'], 'on_sale_now' => self::isOnSale($current)] : null,
            'scheduled'  => $pending ? ['id' => (int) $pending['id'], 'version' => (int) $pending['version'], 'effective_from' => S::iso($pending['valid_from'])] : null,
            'versions'   => $versions,
        ];
    }

    /** Change where a version is sold (website / manage / seller) — visibility, not terms, so allowed once published. */
    public function setChannels(int $id, mixed $channels, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] === 'retired') {
            return ['status' => 409, 'error' => 'A retired version is no longer on sale.'];
        }
        [$ch, $ce] = self::normaliseChannels($channels ?? []);
        if ($ce !== null) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => $ce, 'details' => ['channels' => $ce]];
        }
        $from = self::channelsOf($o);
        $this->db()->query('UPDATE engage_offers SET channels = ?::text[], updated_at = NOW() WHERE id = ?', [S::pgArray($ch), $id]);
        $this->audit('offer.channels_changed', $id, ['offer_code' => $o['offer_code'], 'version' => (int) $o['version'], 'from' => $from, 'to' => $ch]);

        return ['status' => 200, 'data' => self::view($this->findRow($id))];
    }

    // ------------------------------------------------------------------ going live (inside a transaction)

    /**
     * The checks a version must pass before it can go on sale (publish, schedule, revise), or null.
     *
     * @return array<string,mixed>|null an error result
     */
    private function publishProblem(array $o): ?array
    {
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
        if (($ce = $this->courseProblem($o)) !== null) {
            return ['status' => 422, 'code' => 'course_not_licensable', 'error' => $ce, 'details' => ['product_code' => $ce]];
        }
        [, $refused] = ManageAppRegistry::planKeyList(S::fromJsonArray($o['app_keys_json']));
        $noPlan = array_values(array_filter($refused, static fn ($k) => ManageAppRegistry::normalize((string) $k) !== null));
        $unknown = array_values(array_diff($refused, $noPlan));
        if ($unknown !== []) {
            return ['status' => 422, 'error' => 'Manage does not know the app key(s) ' . implode(', ', $unknown) . '. Use Manage\'s keys (e.g. books, inventory, billing, notes, docs).',
                'details' => ['app_keys' => 'Unknown to Manage: ' . implode(', ', $unknown) . '.']];
        }
        if ($noPlan !== []) {
            // Manage refuses an entitlement push for these (422 unknown_application): it holds no plan of its own —
            // max_companies / max_users ride on each application's plan.
            return ['status' => 422, 'error' => 'Manage holds no plan for ' . implode(', ', $noPlan) . ': license the applications themselves (max_companies and max_users ride on each application\'s plan).',
                'details' => ['app_keys' => 'Manage holds no plan for: ' . implode(', ', $noPlan) . '.']];
        }
        $isDefault = in_array($o['is_default'] ?? false, [true, 't', 'true', 1, '1'], true);
        if ($isDefault && ($de = $this->defaultOfferProblem($o, $type)) !== null) {
            return ['status' => 422, 'error' => $de, 'details' => ['is_default' => $de]];
        }
        if ($o['valid_to'] !== null && strtotime((string) $o['valid_to']) <= time()) {
            return ['status' => 422, 'error' => 'valid_to has already passed.', 'details' => ['valid_to' => 'Must be in the future.']];
        }

        return null;
    }

    /**
     * Take a draft live, inside the caller's transaction: scheduled when its valid_from is ahead, else published now
     * with the previous published version retired. The code's rows are locked first, and a scheduled version already in
     * effect is recorded (promoteTx) so the state is current before deciding.
     *
     * @return array{status:int, data?:array<string,mixed>, retired_offer_id?:?int, code?:string, error?:string, details?:array<string,mixed>}
     */
    private function goLiveTx(array $o, array $staff): array
    {
        $db = $this->db();
        $id = (int) $o['id'];
        $code = (string) $o['offer_code'];
        $db->query('SELECT id FROM engage_offers WHERE offer_code = ? FOR UPDATE', [$code]);
        foreach ($db->query("SELECT id FROM engage_offers WHERE offer_code = ? AND status = 'scheduled' AND valid_from <= NOW()", [$code])->getResultArray() as $due) {
            $this->promoteTx((int) $due['id']);
        }
        $pending = $db->query("SELECT version, valid_from FROM engage_offers WHERE offer_code = ? AND status = 'scheduled' AND id <> ?", [$code, $id])->getRowArray();
        if ($pending) {
            return ['status' => 409, 'code' => 'revision_pending', 'error' => "v{$pending['version']} of {$code} is scheduled to take effect on "
                . S::displayDate((string) $pending['valid_from']) . '. Cancel that revision first, or wait until it has taken effect.',
                'details' => ['scheduled_version' => (int) $pending['version'], 'effective_from' => S::iso((string) $pending['valid_from'])]];
        }
        if (self::bool($o['is_default'] ?? false)) {
            $other = $db->query("SELECT offer_code FROM engage_offers WHERE product_code = ? AND is_default AND offer_code <> ? AND status IN ('published','scheduled') LIMIT 1",
                [$o['product_code'], $code])->getRow();
            if ($other) {
                return ['status' => 409, 'code' => 'default_offer_exists', 'error' => "{$other->offer_code} is already the Basic (default) of {$o['product_code']}. Clear its default flag or retire it first."];
            }
        }
        if ($o['valid_from'] !== null && strtotime((string) $o['valid_from']) > time()) {
            $db->query("UPDATE engage_offers SET status = 'scheduled', scheduled_at = NOW(), scheduled_by = ?, updated_at = NOW() WHERE id = ? AND status = 'draft'", [(int) $staff['id'], $id]);

            return ['status' => 200, 'data' => self::view($this->findRow($id)), 'retired_offer_id' => null];
        }
        $prev = $db->query("SELECT id, version FROM engage_offers WHERE offer_code = ? AND status = 'published'", [$code])->getRowArray();
        if ($prev) {
            $db->query("UPDATE engage_offers SET status = 'retired', retired_at = NOW(), retired_by = ?, retired_reason = ?, updated_at = NOW() WHERE id = ?",
                [(int) $staff['id'], "Replaced by v{$o['version']}", (int) $prev['id']]);
        }
        $db->query("UPDATE engage_offers SET status = 'published', published_at = NOW(), published_by = ?, updated_at = NOW() WHERE id = ? AND status = 'draft'", [(int) $staff['id'], $id]);

        return ['status' => 200, 'data' => self::view($this->findRow($id)), 'retired_offer_id' => $prev ? (int) $prev['id'] : null];
    }

    /**
     * scheduled → published for a version whose effective date has come, inside the caller's transaction (rows of its
     * code already locked): the published version before it is retired AT the effective date. 200 with data null when
     * there is nothing to do (already recorded).
     *
     * @return array{status:int, data:?array<string,mixed>}
     */
    private function promoteTx(int $id): array
    {
        $db = $this->db();
        $s = $db->query("SELECT id, offer_code, version, product_code, is_default, valid_from, scheduled_by FROM engage_offers
            WHERE id = ? AND status = 'scheduled' AND valid_from <= NOW()", [$id])->getRowArray();
        if (! $s) {
            return ['status' => 200, 'data' => null];
        }
        $prev = $db->query("SELECT id, version FROM engage_offers WHERE offer_code = ? AND status = 'published'", [$s['offer_code']])->getRowArray();
        if ($prev) {
            $db->query("UPDATE engage_offers SET status = 'retired', retired_at = ?, retired_by = ?, retired_reason = ?, updated_at = NOW() WHERE id = ?",
                [$s['valid_from'], $s['scheduled_by'], "Replaced by v{$s['version']} with effect from " . S::displayDate((string) $s['valid_from']), (int) $prev['id']]);
        }
        $db->query("UPDATE engage_offers SET status = 'published', published_at = valid_from, published_by = scheduled_by, updated_at = NOW()
            WHERE id = ? AND status = 'scheduled'", [$id]);

        return ['status' => 200, 'data' => [
            'offer_code' => (string) $s['offer_code'], 'version' => (int) $s['version'], 'product_code' => (string) $s['product_code'],
            'is_default' => self::bool($s['is_default']), 'replaced_version' => $prev ? (int) $prev['version'] : null,
            'effective_from' => S::iso((string) $s['valid_from']),
        ]];
    }

    /**
     * Run $fn in one transaction with exceptions on. A result with status ≥ 300 rolls back and is returned; an
     * exception rolls back and is rethrown.
     */
    private function tx(callable $fn): array
    {
        $db = $this->db();

        return S::strictTx($db, static function () use ($db, $fn): array {
            $db->transBegin();
            try {
                $r = $fn();
                if ((int) ($r['status'] ?? 500) >= 300) {
                    $db->transRollback();

                    return $r;
                }
                $db->transCommit();

                return $r;
            } catch (\Throwable $e) {
                $db->transRollback();

                throw $e;
            }
        });
    }

    /** tx() for publish / revise: unique-index races answer 409, anything else 500 (logged). */
    private function guarded(array $o, callable $fn): array
    {
        try {
            return $this->tx($fn);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'uq_engage_offers_default')) {
                return ['status' => 409, 'code' => 'default_offer_exists', 'error' => "Another offer is already the published Basic (default) of {$o['product_code']}. Clear its default flag or retire it first."];
            }
            if (str_contains($e->getMessage(), 'uq_engage_offers_scheduled') || str_contains($e->getMessage(), 'uq_engage_offers_published')) {
                return ['status' => 409, 'code' => 'revision_pending', 'error' => "{$o['offer_code']} was changed by someone else at the same time. Reload and try again."];
            }
            log_message('error', 'Offer publish failed: ' . $e->getMessage());

            return ['status' => 500, 'error' => 'Could not publish the offer.'];
        }
    }

    public function retire(int $id, array $staff): array
    {
        $o = $this->findRow($id);
        if (! $o) {
            return ['status' => 404, 'error' => 'Offer not found.'];
        }
        if ($o['status'] !== 'published') {
            return ['status' => 409, 'error' => match ($o['status']) {
                'draft'     => 'A draft is not on sale — discard it instead.',
                'scheduled' => 'This is a scheduled price revision — cancel it instead.',
                default     => 'This version is already retired.',
            }];
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
            // The unique index covers published versions; a scheduled one would collide only when it takes effect.
            if ($o['status'] !== 'draft') {
                $other = $this->db()->query("SELECT offer_code FROM engage_offers WHERE product_code = ? AND is_default AND offer_code <> ? AND status IN ('published','scheduled') LIMIT 1",
                    [$o['product_code'], $o['offer_code']])->getRow();
                if ($other) {
                    return ['status' => 409, 'code' => 'default_offer_exists', 'error' => "{$other->offer_code} is already the Basic (default) of {$o['product_code']}. Clear its default flag first."];
                }
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
        if (($o['licence_scope'] ?? 'workspace') !== 'workspace') {
            return 'The Basic (default) offer covers every company of the holder: set "Applies to" to the whole workspace.';
        }

        return null;
    }

    /**
     * Aicountly Academy course products (Aicountly Edge, Aicountly Aspire, the `academy` umbrella; any product of
     * category Academy) are courses Aicountly runs, never licensed: no offer, licence or entitlement names them.
     */
    public static function isCourseProduct(string $productCode, ?string $category = null): bool
    {
        return ManageAppRegistry::isCourse($productCode) || strcasecmp(trim((string) $category), 'Academy') === 0;
    }

    /** SQL: offer alias $a is not for a course product and names no course app key (constants only). */
    public static function notCourseSql(string $a): string
    {
        $list = ManageAppRegistry::courseKeysSql();

        return "({$a}.product_code NOT IN ({$list}) AND NOT ({$a}.app_keys && ARRAY[{$list}]::text[])
            AND NOT EXISTS (SELECT 1 FROM engage_products cp WHERE cp.code = {$a}.product_code AND LOWER(cp.category) = 'academy'))";
    }

    /** Why $o (an offer row) cannot be published / versioned as a course offer, or null. */
    private function courseProblem(array $o): ?string
    {
        $cat = $this->db()->query('SELECT category FROM engage_products WHERE code = ?', [(string) $o['product_code']])->getRow()->category ?? null;
        if (self::isCourseProduct((string) $o['product_code'], $cat)) {
            return "{$o['product_code']} is an Aicountly Academy course, not a licensable product: no offer can be published for it.";
        }
        if (($course = ManageAppRegistry::courseKeysIn(S::fromJsonArray($o['app_keys_json'] ?? '[]'))) !== []) {
            return implode(', ', $course) . ': Aicountly Academy course(s), not licensable — remove from app_keys.';
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
        return $this->db()->query(self::SELECT . ' WHERE o.product_code = ? AND o.is_default AND ' . self::onSaleSql('o') . ' AND t.active
            AND ' . self::notCourseSql('o') . ' ORDER BY o.version DESC LIMIT 1', [$productCode])->getRowArray() ?: null;
    }

    /** Every product's published default offer on sale. @return list<array<string,mixed>> */
    public function defaultOffers(): array
    {
        return $this->db()->query(self::SELECT . ' WHERE o.is_default AND ' . self::onSaleSql('o') . ' AND t.active AND ' . self::notCourseSql('o') . '
            ORDER BY o.product_code')->getResultArray();
    }

    /**
     * Licensable products with NO published default offer on sale — the Basic nobody can be given.
     *
     * Provisioning (ProvisioningService) iterates defaultOffers(), so a product that is missing from
     * it is simply never reached: the holder is provisioned, the call answers 200, and that product
     * is silently left without a licence. Every sign-up since is short one Basic, and nothing says
     * so. This is the query that lets Engage and Console say so.
     *
     * Course products (Academy, Edge, Aspire) are never licensed and are not a gap. Products whose
     * code Manage does not hold a plan for are excluded too: Engage deliberately never publishes an
     * offer for them (ManageAppRegistry::planKey), so their absence is correct, not a gap.
     *
     * A product that has a default offer still in DRAFT is reported with draft = true: that is the
     * common case right after `engage:basic-offers`, and it needs a different sentence — someone
     * started the work and has not published it.
     *
     * @return list<array{code:string, name:string, draft:bool}>
     */
    public function productsWithoutDefaultOffer(): array
    {
        $rows = $this->db()->query(
            "SELECT p.code, p.name,
                    EXISTS (SELECT 1 FROM engage_offers d
                             WHERE d.product_code = p.code AND d.is_default AND d.status = 'draft') AS has_draft
               FROM engage_products p
              WHERE p.is_active
                AND LOWER(COALESCE(p.category, '')) <> 'academy'
                AND p.code NOT IN (" . ManageAppRegistry::courseKeysSql() . ")
                AND NOT EXISTS (
                    SELECT 1 FROM engage_offers o
                      JOIN engage_licence_types t ON t.code = o.licence_type_code
                     WHERE o.product_code = p.code AND o.is_default AND t.active AND " . self::onSaleSql('o') . ")
              ORDER BY p.code"
        )->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            // Engage never publishes an offer for a code Manage holds no plan for, so its absence
            // is by design rather than an oversight.
            if (ManageAppRegistry::planKey((string) $r['code']) === null) {
                continue;
            }
            $out[] = [
                'code'  => (string) $r['code'],
                'name'  => (string) $r['name'],
                'draft' => in_array($r['has_draft'], [true, 't', '1', 1], true),
            ];
        }

        return $out;
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

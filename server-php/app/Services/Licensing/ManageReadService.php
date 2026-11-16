<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Libraries\Licensing\ManageAppRegistry;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Read model behind the Manage-facing API (commerce contract v1 §4). Every company-scoped query is filtered by
 * cmp_id: another company's licence is answered exactly like a missing one, a company without licences gets an
 * empty list. Lists use the §0.3 cursor (timestamp DESC, id DESC); there are no totals.
 *
 * Aicountly Academy courses (ManageAppRegistry::COURSE_KEYS: academy, edge, aspire) are never licensed: a licence of
 * one (issued before 2026-10-24) is answered like a missing one, its events are left out of the activity feed, and
 * no course offer appears in the catalogue.
 */
class ManageReadService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /**
     * @param array{status?:?string, product_code?:?string} $filters
     * @param array{d:string,id:int}|null $cursor
     * @return array{items: list<array<string,mixed>>, next_cursor: ?string}
     */
    public function licences(int $cmpId, array $filters, int $limit, ?array $cursor): array
    {
        return $this->licencesWhere('l.cmp_id = ?', [$cmpId], $filters, $limit, $cursor);
    }

    /**
     * Licences a person holds (holder_uuid — the my.aicountly uuid of the owner, D-L2): the Basic of each app, plans
     * staff issued to them and plans sold to them through a seller. Same shape, filters and paging as the company list.
     *
     * With $cmpId — one of the companies this person owns, as Manage has verified — the company's legacy company-level
     * licences are included too: everything that applies to that company, in one list with one cursor.
     *
     * @param array{status?:?string, product_code?:?string, is_basic?:?bool} $filters
     * @param array{d:string,id:int}|null $cursor
     * @return array{items: list<array<string,mixed>>, next_cursor: ?string}
     */
    public function holderLicences(string $holder, array $filters, int $limit, ?array $cursor, ?int $cmpId = null): array
    {
        [$sql, $binds] = self::holderScope('l', $holder, $cmpId);

        return $this->licencesWhere($sql, $binds, $filters, $limit, $cursor);
    }

    /**
     * The licences of a holder, or of a holder and one of their companies.
     *
     * @return array{0:string, 1:list<int|string>}
     */
    private static function holderScope(string $a, string $holder, ?int $cmpId): array
    {
        // With a company: what applies to it (docs/LICENSING.md §3.6) — the holder's workspace licences and the company
        // licences applied to that company (the holder's, or legacy ones). Without: everything the holder holds,
        // including company licences applied elsewhere or not applied to a company yet.
        return $cmpId === null ? ["{$a}.holder_uuid = ?", [$holder]]
            : ["(({$a}.holder_uuid = ? AND {$a}.scope = 'workspace') OR ({$a}.scope = 'company' AND {$a}.cmp_id = ?))", [$holder, $cmpId]];
    }

    /**
     * @param list<int|string> $subjectBinds
     * @return array{items: list<array<string,mixed>>, next_cursor: ?string}
     */
    private function licencesWhere(string $subjectSql, array $subjectBinds, array $filters, int $limit, ?array $cursor): array
    {
        $where = [$subjectSql, self::notCourseLicenceSql('l')];
        $binds = $subjectBinds;
        if (isset($filters['is_basic']) && is_bool($filters['is_basic'])) {
            $where[] = $filters['is_basic'] ? 'l.is_basic' : 'NOT l.is_basic';
        }
        if (! empty($filters['status'])) {
            // Filter on the status Manage is shown (a live licence past its end reads "expired").
            if ($filters['status'] === 'expired') {
                $where[] = "(l.status = 'expired' OR (l.status IN ('issued','active','suspended') AND l.ends_at IS NOT NULL AND l.ends_at <= NOW()))";
            } else {
                $where[] = 'l.status = ?';
                $binds[] = $filters['status'];
                if (in_array($filters['status'], LicenceService::LIVE, true)) {
                    $where[] = '(l.ends_at IS NULL OR l.ends_at > NOW())';
                }
            }
        }
        if (! empty($filters['product_code'])) {
            $where[] = 'l.product_code = ?';
            $binds[] = $filters['product_code'];
        }
        if ($cursor !== null) {
            $where[] = '(l.created_at, l.id) < (?::timestamptz, ?)';
            $binds[] = $cursor['d'];
            $binds[] = $cursor['id'];
        }
        $binds[] = $limit + 1;
        $rows = $this->db()->query(
            "SELECT l.*, to_jsonb(l.app_keys) AS app_keys_json, to_jsonb(l.invoice_refs) AS invoice_refs_json, " . S::cursorExpr('l.created_at') . " AS cursor_d,
                sl.seller_code AS sold_by_code, COALESCE(NULLIF(sl.organization, ''), sl.name) AS sold_by_name,
                (SELECT COUNT(*) FROM engage_licence_seats s WHERE s.licence_id = l.id AND s.released_at IS NULL) AS seats_assigned
             FROM engage_licences l LEFT JOIN engage_sellers sl ON sl.id = l.seller_id WHERE " . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ?',
            $binds
        )->getResultArray();

        return $this->page($rows, $limit, [LicenceService::class, 'contractView']);
    }

    /** One licence the holder holds (or, with $cmpId, their company holds); anyone else's answers like a missing one. */
    public function holderLicence(string $holder, string $ref, ?int $cmpId = null): ?array
    {
        $l = (new LicenceService($this->db()))->findForHolder($holder, $ref, $cmpId);

        return $l && ! LicenceService::coversCourse($l) ? LicenceService::contractView($l) : null;
    }

    /** @return array<string,mixed>|null */
    public function holderSeats(string $holder, string $ref, ?int $cmpId = null): ?array
    {
        $svc = new LicenceService($this->db());
        $l = $svc->findForHolder($holder, $ref, $cmpId);
        if (! $l || LicenceService::coversCourse($l)) {
            return null;
        }

        return [
            'licence_ref' => $l['licence_ref'],
            'total'       => $l['seats_total'] !== null ? (int) $l['seats_total'] : null,
            'assigned'    => (int) $l['seats_assigned'],
            'items'       => array_map([LicenceService::class, 'seatView'], $svc->seatsForLicence((int) $l['id'], false)),
        ];
    }

    /**
     * Activity of every licence the holder holds (with $cmpId, and of that company's licences), newest first — or of one
     * of those licences with $licenceRef. Events are numbered per company, or per holder for a licence without one, so
     * `seq` is informational here; the cursor orders the feed.
     *
     * @return array{items: list<array<string,mixed>>, next_cursor: ?string}
     */
    public function holderActivity(string $holder, int $limit, ?array $cursor, ?int $cmpId = null, ?string $licenceRef = null): array
    {
        [$scope, $binds] = self::holderScope('hl', $holder, $cmpId);
        $where = ['e.licence_id IN (SELECT hl.id FROM engage_licences hl WHERE ' . $scope . ' AND ' . self::notCourseLicenceSql('hl') . ')'];
        if ($licenceRef !== null) {
            $where[] = 'e.licence_ref = ?';
            $binds[] = $licenceRef;
        }
        if ($cursor !== null) {
            $where[] = '(e.occurred_at, e.id) < (?::timestamptz, ?)';
            $binds[] = $cursor['d'];
            $binds[] = $cursor['id'];
        }
        $binds[] = $limit + 1;
        $rows = $this->db()->query(
            'SELECT e.*, ' . S::cursorExpr('e.occurred_at') . ' AS cursor_d FROM engage_licence_events e WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.occurred_at DESC, e.id DESC LIMIT ?',
            $binds
        )->getResultArray();

        return $this->page($rows, $limit, [LicenceService::class, 'contractEvent']);
    }

    public function licence(int $cmpId, string $ref): ?array
    {
        $l = (new LicenceService($this->db()))->findForCompany($cmpId, $ref);

        return $l && ! LicenceService::coversCourse($l) ? LicenceService::contractView($l) : null;
    }

    /** @return array<string,mixed>|null */
    public function seats(int $cmpId, string $ref): ?array
    {
        $svc = new LicenceService($this->db());
        $l = $svc->findForCompany($cmpId, $ref);
        if (! $l || LicenceService::coversCourse($l)) {
            return null;
        }

        return [
            'licence_ref' => $l['licence_ref'],
            'total'       => $l['seats_total'] !== null ? (int) $l['seats_total'] : null,
            'assigned'    => (int) $l['seats_assigned'],
            'items'       => array_map([LicenceService::class, 'seatView'], $svc->seatsForLicence((int) $l['id'], false)),
        ];
    }

    /** @return array{items: list<array<string,mixed>>, next_cursor: ?string} */
    public function activity(int $cmpId, int $limit, ?array $cursor, ?string $licenceRef = null): array
    {
        $where = ['e.cmp_id = ?', 'NOT EXISTS (SELECT 1 FROM engage_licences cl WHERE cl.id = e.licence_id AND NOT ' . self::notCourseLicenceSql('cl') . ')'];
        $binds = [$cmpId];
        if ($licenceRef !== null) {
            $where[] = 'e.licence_ref = ?';
            $binds[] = $licenceRef;
        }
        if ($cursor !== null) {
            $where[] = '(e.occurred_at, e.id) < (?::timestamptz, ?)';
            $binds[] = $cursor['d'];
            $binds[] = $cursor['id'];
        }
        $binds[] = $limit + 1;
        $rows = $this->db()->query(
            'SELECT e.*, ' . S::cursorExpr('e.occurred_at') . ' AS cursor_d FROM engage_licence_events e WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.occurred_at DESC, e.id DESC LIMIT ?',
            $binds
        )->getResultArray();

        return $this->page($rows, $limit, [LicenceService::class, 'contractEvent']);
    }

    /**
     * Published offers on sale now. Type-agnostic: every label and display field is data. $cmpId is optional (the
     * catalogue does not vary by company yet); each item carries is_default — the product's Basic plan (§3.1/§3.4).
     *
     * @return list<array<string,mixed>>
     */
    public function catalogue(?int $cmpId = null): array
    {
        $offers = new OfferService($this->db());
        $pending = $offers->pendingRevisions();

        return array_map(static function (array $o) use ($pending): array {
            $v = OfferService::view($o);
            $display = (array) $v['display'];
            $attributes = [];
            foreach ((array) ($display['attributes'] ?? []) as $a) {
                if (is_array($a) && isset($a['label'])) {
                    $attributes[] = ['label' => (string) $a['label'], 'value' => $a['value'] ?? null];
                }
            }
            $cycle = is_string($display['cycle_label'] ?? null) && $display['cycle_label'] !== ''
                ? $display['cycle_label'] : S::cycleLabel($v['term_months'], $v['term_days']);

            return [
                'offer'        => ['code' => $v['offer_code'], 'version' => $v['version']],
                'label'        => $v['label'],
                'description'  => $v['description'],
                'product'      => $v['product'],
                'licence_type' => $v['licence_type'],
                'plan'         => $v['plan'],
                'price'        => ['amount_paise' => $v['price_paise'], 'currency' => $v['currency'], 'basis' => $v['price_basis'], 'gst_rate' => $v['gst_rate'], 'sac' => $v['sac']],
                'term'         => ['months' => $v['term_months'], 'days' => $v['term_days'], 'cycle' => $cycle],
                'seats'        => $v['seats'],
                // Additive: every company the customer owns (workspace), or one company at a time (company).
                'licence_scope' => $v['licence_scope'],
                'app_keys'     => $v['app_keys'],
                'is_default'   => $v['is_default'],
                'entitlements' => $v['entitlements'],
                'attributes'   => $attributes,
                'display'      => $v['display'],
                'valid_from'   => $v['valid_from'],
                'valid_to'     => $v['valid_to'],
                // Additive: a price revision scheduled for this offer (docs/SELLING_LICENCES.md §4). Never a second card —
                // the offer above stays the one on sale until effective_from.
                'upcoming'     => isset($pending[$v['offer_code']]) ? self::upcomingView($pending[$v['offer_code']]) : null,
            ];
        }, array_values(array_filter($offers->onSale(), static fn (array $o): bool => ! LicenceService::coversCourse($o)
            // Manage's channel; a product's Basic is always there (Manage's plans_sync reads the defaults from here).
            && (EntitlementProjector::bool($o['is_default'] ?? false) || in_array('manage', OfferService::channelsOf($o), true)))));
    }

    /** {version, effective_from, price} of a scheduled revision. @param array<string,mixed> $o an offer row */
    public static function upcomingView(array $o): array
    {
        return [
            'version'        => (int) $o['version'],
            'effective_from' => S::iso((string) $o['valid_from']),
            'price'          => ['amount_paise' => (int) $o['price_paise'], 'currency' => (string) $o['currency'], 'basis' => (string) $o['price_basis'],
                'gst_rate' => OfferService::rate((string) $o['gst_rate']), 'sac' => $o['sac']],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function licenceTypes(): array
    {
        return array_map(static fn (array $t) => [
            'code'           => $t['code'],
            'label'          => $t['label'],
            'description'    => $t['description'],
            'lifecycle_kind' => $t['lifecycle_kind'],
            'display_schema' => $t['display_schema'],
        ], (new LicenceTypeService($this->db()))->list(true));
    }

    /** SQL: licence alias $a is not for a course product and names no course app key (constants only). */
    private static function notCourseLicenceSql(string $a): string
    {
        $list = ManageAppRegistry::courseKeysSql();

        return "({$a}.product_code NOT IN ({$list}) AND NOT ({$a}.app_keys && ARRAY[{$list}]::text[]))";
    }

    /** @return array{items: list<array<string,mixed>>, next_cursor: ?string} */
    private function page(array $rows, int $limit, callable $view): array
    {
        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = S::encodeCursor((string) $last['cursor_d'], (int) $last['id']);
        }

        return ['items' => array_map($view, $rows), 'next_cursor' => $next];
    }
}

<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Read model behind the Manage-facing API (commerce contract v1 §4). Every company-scoped query is filtered by
 * cmp_id: another company's licence is answered exactly like a missing one, a company without licences gets an
 * empty list. Lists use the §0.3 cursor (timestamp DESC, id DESC); there are no totals.
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
        $where = ['l.cmp_id = ?'];
        $binds = [$cmpId];
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
            'SELECT l.*, to_jsonb(l.app_keys) AS app_keys_json, to_jsonb(l.invoice_refs) AS invoice_refs_json, ' . S::cursorExpr('l.created_at') . ' AS cursor_d,
                (SELECT COUNT(*) FROM engage_licence_seats s WHERE s.licence_id = l.id AND s.released_at IS NULL) AS seats_assigned
             FROM engage_licences l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ?',
            $binds
        )->getResultArray();

        return $this->page($rows, $limit, [LicenceService::class, 'contractView']);
    }

    public function licence(int $cmpId, string $ref): ?array
    {
        $l = (new LicenceService($this->db()))->findForCompany($cmpId, $ref);

        return $l ? LicenceService::contractView($l) : null;
    }

    /** @return array<string,mixed>|null */
    public function seats(int $cmpId, string $ref): ?array
    {
        $svc = new LicenceService($this->db());
        $l = $svc->findForCompany($cmpId, $ref);
        if (! $l) {
            return null;
        }

        return [
            'licence_ref' => $l['licence_ref'],
            'total'       => $l['seats_total'] !== null ? (int) $l['seats_total'] : null,
            'assigned'    => (int) $l['seats_assigned'],
            'items'       => array_map(static fn ($s) => LicenceService::seatView($s), $svc->seatsForLicence((int) $l['id'], false)),
        ];
    }

    /** @return array{items: list<array<string,mixed>>, next_cursor: ?string} */
    public function activity(int $cmpId, int $limit, ?array $cursor, ?string $licenceRef = null): array
    {
        $where = ['e.cmp_id = ?'];
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

    /** Published offers on sale now. Type-agnostic: every label and display field is data. @return list<array<string,mixed>> */
    public function catalogue(int $cmpId): array
    {
        return array_map(static function (array $o): array {
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
                'app_keys'     => $v['app_keys'],
                'entitlements' => $v['entitlements'],
                'attributes'   => $attributes,
                'display'      => $v['display'],
                'valid_from'   => $v['valid_from'],
                'valid_to'     => $v['valid_to'],
            ];
        }, (new OfferService($this->db()))->onSale());
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

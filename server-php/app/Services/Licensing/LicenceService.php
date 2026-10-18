<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Licences (engage_licences) and their append-only activity (engage_licence_events) — docs/LICENSING.md.
 *
 * Statuses and transitions (anything else answers 409 invalid_transition):
 *
 *   issue        → issued, or active when activate=true (default)          events: issued (+ activated)
 *   activate     issued → active                                          activated
 *   renew        active | expired → active, ends_at += one term            renewed  (same offer_code; latest published version)
 *   extend       issued | active | suspended: ends_at → a later date       extended
 *   suspend      active → suspended (reason)                              suspended
 *   reinstate    suspended → active (reason; term must not have ended)    reinstated
 *   revoke       issued | active | suspended | expired → revoked (final)   revoked
 *   expire       issued | active | suspended with ends_at ≤ now → expired  expired  (system: engage:licences-expire)
 *   seats        issued | active | suspended: seats_total (≥ assigned)     seats_changed
 *   assign seat  issued | active: within seats_total                      seat_assigned
 *   release seat any status                                               seat_released
 *   invoice ref  any status: link an invoice number                       invoice_linked
 *
 * Every write runs in one transaction: the licence row is locked (FOR UPDATE), the caller's `version` must equal
 * the stored one (optimistic lock → 409 version_conflict otherwise), the row is updated with version + 1 and the
 * event(s) are inserted. The event seq per cmp_id is assigned by a trigger. Staff writes are also audited.
 */
class LicenceService
{
    public const STATUSES = ['issued', 'active', 'suspended', 'expired', 'revoked'];
    public const LIVE = ['issued', 'active', 'suspended'];

    private const ALLOWED = [
        'activate'  => ['issued'],
        'renew'     => ['active', 'expired'],
        'extend'    => ['issued', 'active', 'suspended'],
        'suspend'   => ['active'],
        'reinstate' => ['suspended'],
        'revoke'    => ['issued', 'active', 'suspended', 'expired'],
        'expire'    => ['issued', 'active', 'suspended'],
        'seats'     => ['issued', 'active', 'suspended'],
        'assign'    => ['issued', 'active'],
        'release'   => self::STATUSES,
        'invoice'   => self::STATUSES,
    ];

    public const STATUS_LABELS = [
        'issued'    => ['label' => 'Issued', 'tone' => 'info'],
        'active'    => ['label' => 'Active', 'tone' => 'success'],
        'suspended' => ['label' => 'Suspended', 'tone' => 'warning'],
        'expired'   => ['label' => 'Expired', 'tone' => 'neutral'],
        'revoked'   => ['label' => 'Revoked', 'tone' => 'danger'],
    ];

    public const EVENT_LABELS = [
        'issued' => 'Issued', 'activated' => 'Activated', 'renewed' => 'Renewed', 'extended' => 'Extended', 'upgraded' => 'Upgraded',
        'suspended' => 'Suspended', 'reinstated' => 'Reinstated', 'expired' => 'Expired', 'revoked' => 'Revoked',
        'seats_changed' => 'Seats changed', 'seat_assigned' => 'Seat assigned', 'seat_released' => 'Seat released',
        'invoice_linked' => 'Invoice linked',
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    private const SELECT = "SELECT l.*, to_jsonb(l.app_keys) AS app_keys_json, to_jsonb(l.invoice_refs) AS invoice_refs_json,
            a.name AS account_name,
            (SELECT COUNT(*) FROM engage_licence_seats s WHERE s.licence_id = l.id AND s.released_at IS NULL) AS seats_assigned
        FROM engage_licences l JOIN engage_accounts a ON a.id = l.account_id";

    // ------------------------------------------------------------------ views

    /** Status as shown: a live licence whose term has ended reads "expired" even before the expiry job ran. */
    public static function effectiveStatus(array $l): string
    {
        if (in_array($l['status'], self::LIVE, true) && $l['ends_at'] !== null && strtotime((string) $l['ends_at']) <= time()) {
            return 'expired';
        }

        return (string) $l['status'];
    }

    /** Contract §4 shape (type-agnostic; every label comes from the offer snapshot). */
    public static function contractView(array $l): array
    {
        $snap = S::json($l['offer_snapshot']);
        $display = S::json($snap['display'] ?? []);
        $status = self::effectiveStatus($l);
        $cycle = is_string($display['cycle_label'] ?? null) && $display['cycle_label'] !== ''
            ? $display['cycle_label'] : S::cycleLabel((int) ($snap['term_months'] ?? 0), (int) ($snap['term_days'] ?? 0));
        $attributes = [];
        foreach ((array) ($display['attributes'] ?? []) as $a) {
            if (is_array($a) && isset($a['label'])) {
                $attributes[] = ['label' => (string) $a['label'], 'value' => $a['value'] ?? null];
            }
        }

        return [
            'licence_ref'  => $l['licence_ref'],
            'product'      => ['code' => $l['product_code'], 'label' => $snap['product']['label'] ?? $l['product_code']],
            'licence_type' => ['code' => $l['licence_type_code'], 'label' => $snap['licence_type']['label'] ?? $l['licence_type_code'], 'lifecycle_kind' => $snap['licence_type']['lifecycle_kind'] ?? null],
            'plan'         => $l['plan_code'] !== null ? ['code' => $l['plan_code'], 'label' => $snap['plan']['label'] ?? $l['plan_code']] : null,
            'offer'        => ['code' => $l['offer_code'], 'version' => (int) $l['offer_version'], 'label' => $snap['label'] ?? $l['offer_code']],
            'status'       => ['code' => $status] + self::STATUS_LABELS[$status],
            'term'         => ['starts_at' => S::iso($l['starts_at']), 'ends_at' => S::iso($l['ends_at']), 'cycle' => $cycle],
            'seats'        => ['total' => $l['seats_total'] !== null ? (int) $l['seats_total'] : null, 'assigned' => (int) ($l['seats_assigned'] ?? 0)],
            'entitlements' => array_values(array_map(static fn ($x) => [
                'code' => (string) ($x['code'] ?? ''), 'label' => (string) ($x['label'] ?? ''), 'value' => $x['value'] ?? null, 'unit' => $x['unit'] ?? null,
            ], (array) ($snap['entitlements'] ?? []))),
            'attributes'   => $attributes,
            'app_keys'     => S::fromJsonArray($l['app_keys_json'] ?? '[]'),
            'invoice_refs' => S::fromJsonArray($l['invoice_refs_json'] ?? '[]'),
            'issued_at'    => S::iso($l['created_at']),
            'updated_at'   => S::iso($l['updated_at']),
            'version'      => (int) $l['version'],
        ];
    }

    /** Staff view: the contract shape plus internal fields. */
    public static function staffView(array $l): array
    {
        return self::contractView($l) + [
            'id'             => (int) $l['id'],
            'cmp_id'         => (int) $l['cmp_id'],
            'account'        => ['id' => (int) $l['account_id'], 'name' => $l['account_name'] ?? null],
            'stored_status'  => $l['status'],
            'status_reason'  => $l['status_reason'],
            'source'         => $l['source'],
            'order_ref'      => $l['order_ref'],
            'notes'          => $l['notes'],
            'offer_snapshot' => S::json($l['offer_snapshot']),
            'activated_at'   => S::iso($l['activated_at']),
            'suspended_at'   => S::iso($l['suspended_at']),
            'revoked_at'     => S::iso($l['revoked_at']),
            'expired_at'     => S::iso($l['expired_at']),
        ];
    }

    /** Event as Manage sees it: no internal reason, staff names are not disclosed. */
    public static function contractEvent(array $e): array
    {
        $actor = match ($e['actor_kind']) {
            'staff' => 'AICOUNTLY team',
            'order' => 'Online order',
            default => 'AICOUNTLY',
        };

        return [
            'event_id'    => $e['event_id'],
            'seq'         => (int) $e['seq'],
            'licence_ref' => $e['licence_ref'],
            'type'        => ['code' => $e['type'], 'label' => self::EVENT_LABELS[$e['type']] ?? $e['type']],
            'summary'     => $e['summary'],
            'actor'       => ['kind' => $e['actor_kind'], 'display' => $actor],
            'changes'     => (object) S::json($e['changes']),
            'occurred_at' => S::iso($e['occurred_at']),
        ];
    }

    public static function staffEvent(array $e): array
    {
        $v = self::contractEvent($e);
        $v['id'] = (int) $e['id'];
        $v['actor']['display'] = $e['actor_display'];
        $v['reason'] = $e['reason'];
        $v['licence_version'] = (int) $e['licence_version'];

        return $v;
    }

    public static function seatView(array $s, bool $internal = false): array
    {
        $v = [
            'seat_id'      => (int) $s['id'],
            'member_ref'   => $s['member_ref'],
            'member_label' => $s['member_label'],
            'assigned_at'  => S::iso($s['assigned_at']),
            'released_at'  => S::iso($s['released_at']),
        ];

        return $v;
    }

    // ------------------------------------------------------------------ reads (staff)

    public function findByRef(string $ref): ?array
    {
        return $this->db()->query(self::SELECT . ' WHERE l.licence_ref = ?', [$ref])->getRowArray() ?: null;
    }

    /** Scoped read: another company's licence is indistinguishable from a missing one. */
    public function findForCompany(int $cmpId, string $ref): ?array
    {
        return $this->db()->query(self::SELECT . ' WHERE l.licence_ref = ? AND l.cmp_id = ?', [$ref, $cmpId])->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listForStaff(array $q): array
    {
        $where = [];
        $binds = [];
        foreach (['account_id' => 'l.account_id', 'cmp_id' => 'l.cmp_id'] as $k => $col) {
            if (! empty($q[$k]) && ctype_digit((string) $q[$k])) {
                $where[] = "{$col} = ?";
                $binds[] = (int) $q[$k];
            }
        }
        if (! empty($q['status']) && in_array($q['status'], self::STATUSES, true)) {
            $where[] = 'l.status = ?';
            $binds[] = (string) $q['status'];
        }
        if (! empty($q['q'])) {
            $where[] = '(l.licence_ref ILIKE ? OR a.name ILIKE ? OR l.offer_code ILIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $q['q']) . '%';
            array_push($binds, $like, $like, $like);
        }
        $sql = self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY l.created_at DESC, l.id DESC LIMIT 500';

        return array_map([self::class, 'staffView'], $this->db()->query($sql, $binds)->getResultArray());
    }

    /** @return list<array<string,mixed>> */
    public function eventsForLicence(int $licenceId): array
    {
        return $this->db()->query('SELECT * FROM engage_licence_events WHERE licence_id = ? ORDER BY id DESC', [$licenceId])->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function seatsForLicence(int $licenceId, bool $includeReleased = true): array
    {
        return $this->db()->query('SELECT * FROM engage_licence_seats WHERE licence_id = ?' . ($includeReleased ? '' : ' AND released_at IS NULL')
            . ' ORDER BY released_at IS NULL DESC, assigned_at, id', [$licenceId])->getResultArray();
    }

    public function staffDetail(string $ref): ?array
    {
        $l = $this->findByRef($ref);
        if (! $l) {
            return null;
        }

        return self::staffView($l) + [
            'events' => array_map([self::class, 'staffEvent'], $this->eventsForLicence((int) $l['id'])),
            'seat_assignments' => array_map(static fn ($s) => self::seatView($s), $this->seatsForLicence((int) $l['id'])),
        ];
    }

    // ------------------------------------------------------------------ issue

    public function issue(array $in, array $staff): array
    {
        $e = [];
        $accountId = (int) ($in['account_id'] ?? 0);
        $cmpId = (int) (is_numeric($in['cmp_id'] ?? null) ? $in['cmp_id'] : 0);
        if ($accountId < 1) {
            $e['account_id'] = 'Choose the account.';
        }
        if ($cmpId < 1) {
            $e['cmp_id'] = 'Choose the Manage company (cmp_id).';
        }
        $code = trim((string) ($in['offer_code'] ?? ''));
        if ($code === '') {
            $e['offer_code'] = 'Choose a published offer.';
        }
        if ($e !== []) {
            return self::invalid($e);
        }
        if (! (new AccountCompanyService($this->db()))->activeMapping($accountId, $cmpId)) {
            return self::invalid(['cmp_id' => "Company {$cmpId} is not mapped to this account. Map it first (Account → Manage companies)."]);
        }
        $offers = new OfferService($this->db());
        $offer = isset($in['offer_version']) && $in['offer_version'] !== '' && $in['offer_version'] !== null
            ? $offers->findVersion($code, (int) $in['offer_version'])
            : $offers->publishedVersion($code);
        if (! $offer || ! OfferService::isOnSale($offer)) {
            return self::invalid(['offer_code' => 'That offer version is not published and on sale.']);
        }
        $type = (new LicenceTypeService($this->db()))->findByCode($offer['licence_type_code']);
        if (! $type || ! in_array($type['active'], [true, 't', 'true', 1, '1'], true)) {
            return self::invalid(['offer_code' => 'The licence type of this offer is inactive.']);
        }
        $startsAt = array_key_exists('starts_at', $in) && $in['starts_at'] !== null && $in['starts_at'] !== ''
            ? S::parseInstant($in['starts_at']) : gmdate('Y-m-d H:i:s') . '+00';
        if ($startsAt === null) {
            return self::invalid(['starts_at' => 'starts_at must be a date (YYYY-MM-DD) or an ISO-8601 time.']);
        }
        $endsAt = S::addTerm($startsAt, (int) $offer['term_months'], (int) $offer['term_days']);
        $seats = $offer['seats'] !== null ? (int) $offer['seats'] : null;
        if (array_key_exists('seats_total', $in) && $in['seats_total'] !== '' ) {
            if ($in['seats_total'] === null) {
                $seats = null;
            } elseif (! is_numeric($in['seats_total']) || (int) $in['seats_total'] != $in['seats_total'] || (int) $in['seats_total'] < 0) {
                return self::invalid(['seats_total' => 'seats_total must be 0 or more (empty = no seat limit).']);
            } else {
                $seats = (int) $in['seats_total'];
            }
        }
        [$invoiceRefs, $ie] = self::invoiceRefs($in['invoice_refs'] ?? []);
        if ($ie !== null) {
            return self::invalid(['invoice_refs' => $ie]);
        }
        $activate = ! array_key_exists('activate', $in) || filter_var($in['activate'], FILTER_VALIDATE_BOOLEAN);
        $notes = trim((string) ($in['notes'] ?? '')) ?: null;
        $snapshot = OfferService::snapshot($offer);
        $appKeys = S::fromJsonArray($offer['app_keys_json']);
        $actor = self::staffActor($staff);

        $db = $this->db();
        $db->transBegin();
        try {
            $n = (int) $db->query("SELECT nextval('engage_licence_ref_seq') AS n")->getRow()->n;
            $ref = 'LIC-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $now = gmdate('Y-m-d H:i:s') . '+00';
            $id = (int) $db->query(
                'INSERT INTO engage_licences (licence_ref, cmp_id, account_id, offer_id, offer_code, offer_version, offer_snapshot, product_code,
                    licence_type_code, plan_code, app_keys, seats_total, starts_at, ends_at, status, invoice_refs, source, notes, version,
                    activated_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::text[], ?, ?, ?, ?, ?::text[], ?, ?, 1, ?, ?) RETURNING id',
                [$ref, $cmpId, $accountId, (int) $offer['id'], $offer['offer_code'], (int) $offer['version'], json_encode($snapshot),
                    $offer['product_code'], $offer['licence_type_code'], $offer['plan_code'], S::pgArray($appKeys), $seats, $startsAt, $endsAt,
                    $activate ? 'active' : 'issued', S::pgArray($invoiceRefs), 'staff', $notes, $activate ? $now : null, (int) $staff['id']]
            )->getRow()->id;
            $termText = $endsAt ? ' from ' . S::displayDate($startsAt) . ' to ' . S::displayDate(self::lastDay($endsAt)) : ' from ' . S::displayDate($startsAt);
            $this->event($id, $ref, $cmpId, 'issued', $actor, 'Licence issued: ' . $offer['label'] . $termText, null, [
                'offer' => ['code' => $offer['offer_code'], 'version' => (int) $offer['version']],
                'starts_at' => S::iso($startsAt), 'ends_at' => S::iso($endsAt), 'seats_total' => $seats,
                'invoice_refs' => $invoiceRefs,
            ], 1);
            if ($activate) {
                $this->event($id, $ref, $cmpId, 'activated', $actor, 'Licence activated', null, ['status' => ['from' => 'issued', 'to' => 'active']], 1);
            }
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();
            log_message('error', 'Licence issue failed: ' . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not issue the licence.'];
        }
        $this->audit('licence.issued', $ref, ['cmp_id' => $cmpId, 'account_id' => $accountId, 'offer_code' => $offer['offer_code'], 'offer_version' => (int) $offer['version'], 'activated' => $activate]);

        return ['status' => 201, 'data' => $this->staffDetail($ref)];
    }

    // ------------------------------------------------------------------ transitions

    public function activate(string $ref, array $in, array $staff): array
    {
        return $this->mutate($ref, $in, 'activate', $staff, static fn (array $l) => [
            ['status' => 'active', 'activated_at' => gmdate('Y-m-d H:i:s') . '+00', 'status_reason' => null],
            [['activated', 'Licence activated', ['status' => ['from' => 'issued', 'to' => 'active']]]],
        ]);
    }

    public function suspend(string $ref, array $in, array $staff): array
    {
        $reason = self::reason($in);
        if ($reason === null) {
            return self::invalid(['reason' => 'A reason is required to suspend a licence.']);
        }

        return $this->mutate($ref, $in, 'suspend', $staff, static fn (array $l) => [
            ['status' => 'suspended', 'suspended_at' => gmdate('Y-m-d H:i:s') . '+00', 'status_reason' => $reason],
            [['suspended', 'Licence suspended', ['status' => ['from' => 'active', 'to' => 'suspended']], $reason]],
        ]);
    }

    public function reinstate(string $ref, array $in, array $staff): array
    {
        $reason = self::reason($in);
        if ($reason === null) {
            return self::invalid(['reason' => 'A reason is required to reinstate a licence.']);
        }

        return $this->mutate($ref, $in, 'reinstate', $staff, static function (array $l) use ($reason) {
            if ($l['ends_at'] !== null && strtotime((string) $l['ends_at']) <= time()) {
                return ['status' => 409, 'code' => 'invalid_transition', 'error' => 'The term of this licence has ended — it can no longer be reinstated. Renew it instead.'];
            }

            return [
                ['status' => 'active', 'suspended_at' => null, 'status_reason' => null],
                [['reinstated', 'Licence reinstated', ['status' => ['from' => 'suspended', 'to' => 'active']], $reason]],
            ];
        });
    }

    public function revoke(string $ref, array $in, array $staff): array
    {
        $reason = self::reason($in);
        if ($reason === null) {
            return self::invalid(['reason' => 'A reason is required to revoke a licence.']);
        }

        return $this->mutate($ref, $in, 'revoke', $staff, static fn (array $l) => [
            ['status' => 'revoked', 'revoked_at' => gmdate('Y-m-d H:i:s') . '+00', 'status_reason' => $reason],
            [['revoked', 'Licence revoked', ['status' => ['from' => $l['status'], 'to' => 'revoked']], $reason]],
        ]);
    }

    public function extend(string $ref, array $in, array $staff): array
    {
        $reason = self::reason($in);
        $to = S::parseInstant($in['ends_at'] ?? null);
        $e = [];
        if ($reason === null) {
            $e['reason'] = 'A reason is required to extend a licence.';
        }
        if ($to === null) {
            $e['ends_at'] = 'ends_at must be a date (YYYY-MM-DD) or an ISO-8601 time.';
        }
        if ($e !== []) {
            return self::invalid($e);
        }

        return $this->mutate($ref, $in, 'extend', $staff, static function (array $l) use ($to, $reason) {
            if ($l['ends_at'] === null) {
                return ['status' => 409, 'code' => 'invalid_transition', 'error' => 'This licence has no end date, so there is nothing to extend.'];
            }
            if (strtotime($to) <= strtotime((string) $l['ends_at']) || strtotime($to) <= time()) {
                return self::invalid(['ends_at' => 'The new end must be later than the current end and in the future.']);
            }

            return [
                ['ends_at' => $to],
                [['extended', 'Licence extended until ' . S::displayDate(self::lastDay($to)), ['ends_at' => ['from' => S::iso($l['ends_at']), 'to' => S::iso($to)]], $reason]],
            ];
        });
    }

    public function renew(string $ref, array $in, array $staff): array
    {
        $offers = new OfferService($this->db());

        return $this->mutate($ref, $in, 'renew', $staff, function (array $l) use ($offers, $in) {
            $code = $l['offer_code'];
            if (isset($in['offer_code']) && $in['offer_code'] !== '' && $in['offer_code'] !== $code) {
                return self::invalid(['offer_code' => 'A renewal keeps the same offer. Moving to another offer is an upgrade (not available yet).']);
            }
            $offer = isset($in['offer_version']) && $in['offer_version'] !== '' && $in['offer_version'] !== null
                ? $offers->findVersion($code, (int) $in['offer_version']) : $offers->publishedVersion($code);
            if (! $offer || ! OfferService::isOnSale($offer)) {
                return self::invalid(['offer_version' => "Offer {$code} has no published version on sale to renew with."]);
            }
            if ((int) $offer['term_months'] === 0 && (int) $offer['term_days'] === 0) {
                return ['status' => 409, 'code' => 'invalid_transition', 'error' => 'This offer has no term (open-ended), so it cannot be renewed.'];
            }
            if ($l['ends_at'] === null) {
                return ['status' => 409, 'code' => 'invalid_transition', 'error' => 'This licence has no end date, so there is nothing to renew.'];
            }
            [$invoiceRefs, $ie] = self::invoiceRefs($in['invoice_refs'] ?? []);
            if ($ie !== null) {
                return self::invalid(['invoice_refs' => $ie]);
            }
            $now = gmdate('Y-m-d H:i:s') . '+00';
            $base = strtotime((string) $l['ends_at']) > time() ? (string) $l['ends_at'] : $now;
            $newEnd = S::addTerm($base, (int) $offer['term_months'], (int) $offer['term_days']);
            $changes = ['ends_at' => ['from' => S::iso($l['ends_at']), 'to' => S::iso($newEnd)]];
            $set = ['ends_at' => $newEnd, 'status' => 'active', 'expired_at' => null, 'status_reason' => null];
            if ($l['status'] !== 'active') {
                $changes['status'] = ['from' => $l['status'], 'to' => 'active'];
            }
            if ((int) $offer['version'] !== (int) $l['offer_version']) {
                $changes['offer_version'] = ['from' => (int) $l['offer_version'], 'to' => (int) $offer['version']];
                $set += [
                    'offer_id' => (int) $offer['id'], 'offer_version' => (int) $offer['version'],
                    'offer_snapshot' => json_encode(OfferService::snapshot($offer)), 'plan_code' => $offer['plan_code'],
                    'app_keys' => S::fromJsonArray($offer['app_keys_json']),
                ];
            }
            if ($invoiceRefs !== []) {
                $set['invoice_refs'] = array_values(array_unique(array_merge(S::fromJsonArray($l['invoice_refs_json']), $invoiceRefs)));
                $changes['invoice_refs_added'] = $invoiceRefs;
            }

            return [$set, [['renewed', 'Licence renewed until ' . S::displayDate(self::lastDay($newEnd)), $changes, self::reason($in)]]];
        });
    }

    public function changeSeats(string $ref, array $in, array $staff): array
    {
        $v = $in['seats_total'] ?? null;
        if ($v !== null && (! is_numeric($v) || (int) $v != $v || (int) $v < 0)) {
            return self::invalid(['seats_total' => 'seats_total must be 0 or more (null = no seat limit).']);
        }
        $new = $v === null ? null : (int) $v;

        return $this->mutate($ref, $in, 'seats', $staff, static function (array $l) use ($new, $in) {
            $old = $l['seats_total'] !== null ? (int) $l['seats_total'] : null;
            if ($old === $new) {
                return self::invalid(['seats_total' => 'That is already the number of seats.']);
            }
            if ($new !== null && $new < (int) $l['seats_assigned']) {
                return ['status' => 409, 'code' => 'seats_in_use', 'error' => "{$l['seats_assigned']} seats are assigned. Release some before reducing to {$new}."];
            }
            $fmt = static fn (?int $n) => $n === null ? 'unlimited' : (string) $n;

            return [
                ['seats_total' => $new],
                [['seats_changed', 'Seats changed from ' . $fmt($old) . ' to ' . $fmt($new), ['seats_total' => ['from' => $old, 'to' => $new]], self::reason($in)]],
            ];
        });
    }

    public function assignSeat(string $ref, array $in, array $staff): array
    {
        $member = trim((string) ($in['member_ref'] ?? ''));
        $label = trim((string) ($in['member_label'] ?? ''));
        if (mb_strlen($member) > 191 || mb_strlen($label) > 191) {
            return self::invalid(['member_ref' => 'Member identifier / label is too long.']);
        }

        return $this->mutate($ref, $in, 'assign', $staff, function (array $l) use ($member, $label, $staff) {
            if ($l['seats_total'] !== null && (int) $l['seats_assigned'] >= (int) $l['seats_total']) {
                return ['status' => 409, 'code' => 'no_free_seat', 'error' => 'Every seat is assigned. Add seats or release one first.'];
            }
            if ($member !== '' && $this->db()->table('engage_licence_seats')->where('licence_id', (int) $l['id'])->where('member_ref', $member)->where('released_at', null)->countAllResults() > 0) {
                return ['status' => 409, 'code' => 'seat_already_assigned', 'error' => 'This member already holds a seat on this licence.'];
            }
            $seatId = (int) $this->db()->query(
                'INSERT INTO engage_licence_seats (licence_id, licence_ref, cmp_id, member_ref, member_label, assigned_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [(int) $l['id'], $l['licence_ref'], (int) $l['cmp_id'], $member !== '' ? $member : null, $label !== '' ? $label : null, (int) $staff['id']]
            )->getRow()->id;

            return [[], [['seat_assigned', 'Seat assigned' . ($label !== '' ? ' to ' . $label : ($member !== '' ? ' to ' . $member : '')), [
                'seat_id' => $seatId, 'member_ref' => $member !== '' ? $member : null, 'seats_assigned' => ['from' => (int) $l['seats_assigned'], 'to' => (int) $l['seats_assigned'] + 1],
            ]]]];
        });
    }

    public function releaseSeat(string $ref, int $seatId, array $in, array $staff): array
    {
        return $this->mutate($ref, $in, 'release', $staff, function (array $l) use ($seatId, $staff) {
            $seat = $this->db()->query('SELECT * FROM engage_licence_seats WHERE id = ? AND licence_id = ? FOR UPDATE', [$seatId, (int) $l['id']])->getRowArray();
            if (! $seat) {
                return ['status' => 404, 'code' => 'not_found', 'error' => 'Seat not found on this licence.'];
            }
            if ($seat['released_at'] !== null) {
                return ['status' => 409, 'code' => 'invalid_transition', 'error' => 'This seat is already released.'];
            }
            $this->db()->query('UPDATE engage_licence_seats SET released_at = NOW(), released_by = ? WHERE id = ?', [(int) $staff['id'], $seatId]);
            $who = $seat['member_label'] ?: $seat['member_ref'];

            return [[], [['seat_released', 'Seat released' . ($who ? ' from ' . $who : ''), [
                'seat_id' => $seatId, 'member_ref' => $seat['member_ref'], 'seats_assigned' => ['from' => (int) $l['seats_assigned'], 'to' => (int) $l['seats_assigned'] - 1],
            ]]]];
        });
    }

    public function linkInvoice(string $ref, array $in, array $staff): array
    {
        [$refs, $ie] = self::invoiceRefs([$in['invoice_ref'] ?? '']);
        if ($ie !== null || $refs === []) {
            return self::invalid(['invoice_ref' => $ie ?? 'invoice_ref is required.']);
        }
        $inv = $refs[0];

        return $this->mutate($ref, $in, 'invoice', $staff, static function (array $l) use ($inv) {
            $current = S::fromJsonArray($l['invoice_refs_json']);
            if (in_array($inv, $current, true)) {
                return ['status' => 409, 'code' => 'already_linked', 'error' => "Invoice {$inv} is already linked."];
            }

            return [['invoice_refs' => array_merge($current, [$inv])], [['invoice_linked', "Invoice {$inv} linked", ['invoice_ref' => $inv]]]];
        });
    }

    /** System expiry of live licences whose term has ended. @return int licences expired */
    public function expireDue(int $limit = 500): int
    {
        $refs = $this->db()->query("SELECT licence_ref, version FROM engage_licences WHERE status IN ('issued','active','suspended')
            AND ends_at IS NOT NULL AND ends_at <= NOW() ORDER BY ends_at LIMIT " . max(1, $limit))->getResultArray();
        $n = 0;
        foreach ($refs as $r) {
            $res = $this->mutate($r['licence_ref'], ['version' => (int) $r['version']], 'expire', null, static function (array $l) {
                if ($l['ends_at'] === null || strtotime((string) $l['ends_at']) > time()) {
                    return ['status' => 409, 'code' => 'invalid_transition', 'error' => 'Not due.'];
                }

                return [
                    ['status' => 'expired', 'expired_at' => gmdate('Y-m-d H:i:s') . '+00'],
                    [['expired', 'Licence expired', ['status' => ['from' => $l['status'], 'to' => 'expired']]]],
                ];
            });
            if (($res['status'] ?? 0) === 200) {
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ core

    /**
     * @param callable(array): array $fn returns [set, events] — events: list of [type, summary, changes, reason?] —
     *                                   or an error result ['status' => 4xx, …]
     */
    private function mutate(string $ref, array $in, string $action, ?array $staff, callable $fn): array
    {
        $expected = $in['version'] ?? null;
        if (! (is_int($expected) || (is_string($expected) && ctype_digit($expected))) || (int) $expected < 1) {
            return self::invalid(['version' => 'Send the licence version you are changing (optimistic lock).']);
        }
        $expected = (int) $expected;
        $actor = $staff !== null ? self::staffActor($staff) : ['kind' => 'system', 'id' => null, 'display' => 'Engage (scheduled expiry)'];
        $db = $this->db();
        $db->transBegin();
        try {
            $l = $db->query(self::SELECT . ' WHERE l.licence_ref = ? FOR UPDATE OF l', [$ref])->getRowArray();
            if (! $l) {
                $db->transRollback();

                return ['status' => 404, 'code' => 'not_found', 'error' => 'Licence not found.'];
            }
            if ((int) $l['version'] !== $expected) {
                $db->transRollback();

                return ['status' => 409, 'code' => 'version_conflict', 'error' => 'This licence was changed by someone else. Reload and try again.', 'details' => ['current_version' => (int) $l['version']]];
            }
            if (! in_array($l['status'], self::ALLOWED[$action], true)) {
                $db->transRollback();

                return ['status' => 409, 'code' => 'invalid_transition', 'error' => "A licence that is {$l['status']} cannot be changed this way ({$action})."];
            }
            $out = $fn($l);
            if (isset($out['status']) && is_int($out['status'])) {
                $db->transRollback();

                return $out;
            }
            [$set, $events] = $out;
            $sets = ['version = version + 1', 'updated_at = NOW()'];
            $binds = [];
            foreach ($set as $k => $v) {
                if (in_array($k, ['app_keys', 'invoice_refs'], true)) {
                    $sets[] = "{$k} = ?::text[]";
                    $binds[] = S::pgArray($v);
                } elseif ($k === 'offer_snapshot') {
                    $sets[] = "{$k} = ?::jsonb";
                    $binds[] = $v;
                } else {
                    $sets[] = "{$k} = ?";
                    $binds[] = $v;
                }
            }
            $binds[] = (int) $l['id'];
            $binds[] = $expected;
            $db->query('UPDATE engage_licences SET ' . implode(', ', $sets) . ' WHERE id = ? AND version = ?', $binds);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();

                return ['status' => 409, 'code' => 'version_conflict', 'error' => 'This licence was changed by someone else. Reload and try again.'];
            }
            foreach ($events as $ev) {
                $this->event((int) $l['id'], $l['licence_ref'], (int) $l['cmp_id'], $ev[0], $actor, $ev[1], $ev[3] ?? null, $ev[2], $expected + 1);
            }
            $db->transCommit();
        } catch (\Throwable $ex) {
            $db->transRollback();
            log_message('error', "Licence {$action} failed for {$ref}: " . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not change the licence.'];
        }
        $meta = ['cmp_id' => (int) $l['cmp_id'], 'version' => $expected + 1, 'events' => array_map(static fn ($ev) => $ev[0], $events)];
        if (! empty($events[0][3])) {
            $meta['reason'] = $events[0][3];
        }
        $this->audit('licence.' . $action, $l['licence_ref'], $meta, $staff === null);

        return ['status' => 200, 'data' => $this->staffDetail($l['licence_ref'])];
    }

    /** @param array{kind:string,id:?int,display:string} $actor */
    private function event(int $licenceId, string $ref, int $cmpId, string $type, array $actor, string $summary, ?string $reason, array $changes, int $licenceVersion): void
    {
        $this->db()->query(
            'INSERT INTO engage_licence_events (event_id, licence_id, licence_ref, cmp_id, seq, type, actor_kind, actor_id, actor_display, summary, reason, changes, licence_version)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
            ['lev_' . bin2hex(random_bytes(12)), $licenceId, $ref, $cmpId, $type, $actor['kind'], $actor['id'], $actor['display'], $summary, $reason,
                json_encode((object) $changes), $licenceVersion]
        );
    }

    /** @return array{kind:string,id:int,display:string} */
    private static function staffActor(array $staff): array
    {
        return ['kind' => 'staff', 'id' => (int) $staff['id'], 'display' => (string) ($staff['email'] ?? ('staff #' . (int) $staff['id']))];
    }

    private static function reason(array $in): ?string
    {
        $r = trim((string) ($in['reason'] ?? ''));

        return $r === '' ? null : mb_substr($r, 0, 2000);
    }

    /** ends_at is exclusive; the last day of the term is the day before it. */
    private static function lastDay(string $endsAtDb): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($endsAtDb) - 1) . '+00';
    }

    /** @return array{0: list<string>, 1: ?string} */
    private static function invoiceRefs(mixed $in): array
    {
        if ($in === null || $in === '' || $in === []) {
            return [[], null];
        }
        if (is_string($in)) {
            $in = array_map('trim', explode(',', $in));
        }
        if (! is_array($in)) {
            return [[], 'invoice_refs must be a list of invoice numbers.'];
        }
        $out = [];
        foreach ($in as $r) {
            $r = trim((string) $r);
            if ($r === '') {
                continue;
            }
            if (! preg_match('#\A[A-Za-z0-9/_.\-]{1,40}\z#', $r)) {
                return [[], 'An invoice number may use letters, digits and / _ . - (40 characters at most).'];
            }
            $out[] = $r;
        }

        return [array_values(array_unique($out)), null];
    }

    private static function invalid(array $details): array
    {
        return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the request.', 'details' => $details];
    }

    private function audit(string $event, string $ref, array $meta, bool $system = false): void
    {
        $opts = ['subject_kind' => 'licence', 'subject_id' => $ref, 'metadata' => $meta, 'fanout_console' => true];
        if ($system) {
            $opts += ['actor_email' => 'system:engage', 'actor_role' => 'system'];
        }
        Services::auditService()->log($event, $opts);
    }
}

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
 *   change-offer issued | active | suspended → another published offer of   plan_changed  (upgrade / downgrade)
 *                the same product (term kept, or restarted)
 *
 * Holder-level licences (contract §3.1, D-L2): a licence names the holder (my.aicountly uuid of the owner), a
 * Manage company (legacy), or both. A Basic licence (is_basic, from the product's default offer) is `superseded`
 * while a paid licence of the same holder + product is active, and active again when it ends (revoke, expiry,
 * suspension) — reconcileBasic(), in the same transaction as the change that caused it.
 *
 * Every write runs in one transaction: the licence row is locked (FOR UPDATE), the caller's `version` must equal
 * the stored one (optimistic lock → 409 version_conflict otherwise), the row is updated with version + 1, the
 * event(s) are inserted, LicenceHooks runs (issue / renew / change-offer) and the subject's entitlement state is
 * queued for Manage (EntitlementOutbox) — all of it commits together or not at all. The event seq is per cmp_id
 * (per holder for a licence without a company), assigned by a trigger. Staff writes are also audited.
 */
class LicenceService
{
    public const STATUSES = ['issued', 'active', 'suspended', 'expired', 'revoked', 'superseded'];
    public const LIVE = ['issued', 'active', 'suspended'];
    /** A Basic counts as held (one per holder + product) in these statuses. */
    public const BASIC_HELD = ['issued', 'active', 'suspended', 'superseded'];

    private const ALLOWED = [
        'activate'  => ['issued'],
        'renew'     => ['active', 'expired'],
        'extend'    => ['issued', 'active', 'suspended'],
        'suspend'   => ['active'],
        'reinstate' => ['suspended'],
        'revoke'    => ['issued', 'active', 'suspended', 'expired', 'superseded'],
        'expire'    => ['issued', 'active', 'suspended'],
        'seats'     => ['issued', 'active', 'suspended'],
        'assign'    => ['issued', 'active'],
        'release'   => self::STATUSES,
        'invoice'   => self::STATUSES,
        'change-offer' => ['issued', 'active', 'suspended'],
    ];

    public const STATUS_LABELS = [
        'issued'    => ['label' => 'Issued', 'tone' => 'info'],
        'active'    => ['label' => 'Active', 'tone' => 'success'],
        'suspended' => ['label' => 'Suspended', 'tone' => 'warning'],
        'expired'   => ['label' => 'Expired', 'tone' => 'neutral'],
        'revoked'   => ['label' => 'Revoked', 'tone' => 'danger'],
        'superseded' => ['label' => 'Superseded', 'tone' => 'neutral'],
    ];

    public const EVENT_LABELS = [
        'issued' => 'Issued', 'activated' => 'Activated', 'renewed' => 'Renewed', 'extended' => 'Extended', 'upgraded' => 'Upgraded',
        'suspended' => 'Suspended', 'reinstated' => 'Reinstated', 'expired' => 'Expired', 'revoked' => 'Revoked',
        'seats_changed' => 'Seats changed', 'seat_assigned' => 'Seat assigned', 'seat_released' => 'Seat released',
        'invoice_linked' => 'Invoice linked', 'plan_changed' => 'Plan changed', 'superseded' => 'Superseded by a paid plan',
        'reactivated' => 'Basic plan active again',
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
        FROM engage_licences l LEFT JOIN engage_accounts a ON a.id = l.account_id";

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
            'cmp_id'         => $l['cmp_id'] !== null ? (int) $l['cmp_id'] : null,
            'holder_uuid'    => $l['holder_uuid'] ?? null,
            'is_basic'       => EntitlementProjector::bool($l['is_basic'] ?? false),
            'is_addon'       => EntitlementProjector::bool($l['is_addon'] ?? false),
            'holder_note'    => $l['holder_note'] ?? null,
            'superseded_at'  => S::iso($l['superseded_at'] ?? null),
            'account'        => $l['account_id'] !== null ? ['id' => (int) $l['account_id'], 'name' => $l['account_name'] ?? null] : null,
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
            'staff' => 'Aicountly team',
            'order' => 'Online order',
            default => 'Aicountly',
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

    public static function seatView(array $s): array
    {
        return [
            'seat_id'      => (int) $s['id'],
            'member_ref'   => $s['member_ref'],
            'member_label' => $s['member_label'],
            'assigned_at'  => S::iso($s['assigned_at']),
            'released_at'  => S::iso($s['released_at']),
        ];
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
        if (! empty($q['holder_uuid']) && is_string($q['holder_uuid'])) {
            $where[] = 'l.holder_uuid = ?';
            $binds[] = $q['holder_uuid'];
        }
        if (isset($q['is_basic']) && $q['is_basic'] !== '') {
            $where[] = filter_var($q['is_basic'], FILTER_VALIDATE_BOOLEAN) ? 'l.is_basic' : 'NOT l.is_basic';
        }
        if (! empty($q['status']) && in_array($q['status'], self::STATUSES, true)) {
            $where[] = 'l.status = ?';
            $binds[] = (string) $q['status'];
        }
        if (! empty($q['q'])) {
            $where[] = '(l.licence_ref ILIKE ? OR a.name ILIKE ? OR l.offer_code ILIKE ? OR l.holder_uuid ILIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $q['q']) . '%';
            array_push($binds, $like, $like, $like, $like);
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
            'seat_assignments' => array_map([self::class, 'seatView'], $this->seatsForLicence((int) $l['id'])),
        ];
    }

    // ------------------------------------------------------------------ issue

    /**
     * Staff issue. The licence names the holder (holder_uuid — the my.aicountly owner, contract §3.1), a company mapped
     * to the account (cmp_id — legacy), or both. Guards (hazards G16):
     *   - `idempotency_key` (body, or the Idempotency-Key header): a retry with the same key and the same request
     *     answers the licence already issued (`replayed: true`); the same key with another request → 409
     *     idempotency_conflict.
     *   - a second live (issued / active / suspended, term not ended) paid licence of the same product for the same
     *     holder or company → 409 live_licence_exists, unless `is_addon: true`.
     * Optional `quote_ref` is validated for shape and handed to LicenceHooks (coupons) — stored nowhere here.
     */
    public function issue(array $in, array $staff): array
    {
        return S::strictTx($this->db(), fn () => $this->issueTx($in, $staff));
    }

    private function issueTx(array $in, array $staff): array
    {
        $e = [];
        $accountId = (int) ($in['account_id'] ?? 0);
        $cmpId = (int) (is_numeric($in['cmp_id'] ?? null) ? $in['cmp_id'] : 0);
        $holder = trim((string) ($in['holder_uuid'] ?? ''));
        if ($accountId < 1) {
            $e['account_id'] = 'Choose the account.';
        }
        if ($cmpId < 1 && $holder === '') {
            $e['holder_uuid'] = 'Choose the holder (the my.aicountly account owner) or a mapped Manage company (cmp_id).';
        } elseif ($holder !== '' && ! self::validHolder($holder)) {
            $e['holder_uuid'] = 'holder_uuid is the my.aicountly uuid (1–64 letters, digits, . _ : -).';
        }
        $code = trim((string) ($in['offer_code'] ?? ''));
        if ($code === '') {
            $e['offer_code'] = 'Choose a published offer.';
        }
        [$idem, $idemErr] = self::idempotencyKey($in);
        if ($idemErr !== null) {
            $e['idempotency_key'] = $idemErr;
        }
        if (($qe = self::quoteRefError($in)) !== null) {
            $e['quote_ref'] = $qe;
        }
        if ($e !== []) {
            return self::invalid($e);
        }
        $isAddon = array_key_exists('is_addon', $in) && filter_var($in['is_addon'], FILTER_VALIDATE_BOOLEAN);
        $requestHash = hash('sha256', (string) json_encode([
            $accountId, $cmpId, $holder, $code, $in['offer_version'] ?? null, $in['starts_at'] ?? null,
            array_key_exists('seats_total', $in) ? $in['seats_total'] : '(offer)', $in['invoice_refs'] ?? [], $in['activate'] ?? true,
            $isAddon, $in['notes'] ?? null, $in['quote_ref'] ?? null,
        ]));
        if ($idem !== null && ($replay = $this->replay($idem, $requestHash)) !== null) {
            return $replay;
        }
        if ($cmpId > 0 && ! (new AccountCompanyService($this->db()))->activeMapping($accountId, $cmpId)) {
            return self::invalid(['cmp_id' => "Company {$cmpId} is not mapped to this account. Map it first (Account → Manage companies)."]);
        }
        $holderNote = trim((string) ($in['holder_note'] ?? '')) ?: null;
        if ($holder !== '' && $holderNote === null && ! $this->holderKnown($holder)) {
            return self::invalid(['holder_note' => 'Engage has not heard of this holder yet (no my.aicountly sign-up received). Check the uuid with the customer and say how you verified it.']);
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
        $isBasic = EntitlementProjector::bool($offer['is_default'] ?? false);
        if ($isBasic && $holder === '') {
            return self::invalid(['holder_uuid' => 'The Basic plan is held by a person: give the holder_uuid.']);
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
        $holderOrNull = $holder !== '' ? $holder : null;
        $cmpOrNull = $cmpId > 0 ? $cmpId : null;

        $db = $this->db();
        $db->transBegin();
        try {
            // Serialise issues for one subject + product (duplicate guard below is then race-free).
            $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['licence-subject:' . ($holderOrNull ?? 'cmp:' . $cmpId) . ':' . $offer['product_code']]);
            if (! $isAddon && ! $isBasic && ($dup = $this->liveDuplicate($holderOrNull, $cmpOrNull, (string) $offer['product_code'])) !== null) {
                $db->transRollback();

                return ['status' => 409, 'code' => 'live_licence_exists', 'error' => "{$dup} is already live for this " . ($holderOrNull !== null ? 'holder' : 'company')
                    . " and product. Renew or change its plan instead, or tick \"add-on\" to issue a second licence on purpose.", 'details' => ['licence_ref' => $dup]];
            }
            if ($isBasic && ($b = $this->heldBasic($holder, (string) $offer['product_code'])) !== null) {
                $db->transRollback();

                return ['status' => 409, 'code' => 'basic_exists', 'error' => "This holder already holds the Basic plan ({$b['licence_ref']}).", 'details' => ['licence_ref' => $b['licence_ref']]];
            }
            $n = (int) $db->query("SELECT nextval('engage_licence_ref_seq') AS n")->getRow()->n;
            $ref = 'LIC-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $now = gmdate('Y-m-d H:i:s') . '+00';
            $id = (int) $db->query(
                'INSERT INTO engage_licences (licence_ref, cmp_id, holder_uuid, account_id, offer_id, offer_code, offer_version, offer_snapshot, product_code,
                    licence_type_code, plan_code, app_keys, seats_total, starts_at, ends_at, status, invoice_refs, source, notes, version,
                    activated_at, created_by, is_basic, is_addon, idempotency_key, request_hash, holder_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::text[], ?, ?, ?, ?, ?::text[], ?, ?, 1, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$ref, $cmpOrNull, $holderOrNull, $accountId, (int) $offer['id'], $offer['offer_code'], (int) $offer['version'], json_encode($snapshot),
                    $offer['product_code'], $offer['licence_type_code'], $offer['plan_code'], S::pgArray($appKeys), $seats, $startsAt, $endsAt,
                    $activate ? 'active' : 'issued', S::pgArray($invoiceRefs), 'staff', $notes, $activate ? $now : null, (int) $staff['id'],
                    $isBasic, $isAddon, $idem, $idem !== null ? $requestHash : null, $holderNote]
            )->getRow()->id;
            $termText = $endsAt ? ' from ' . S::displayDate($startsAt) . ' to ' . S::displayDate(self::lastDay($endsAt)) : ' from ' . S::displayDate($startsAt);
            $this->event($id, $ref, $cmpOrNull, $holderOrNull, 'issued', $actor, 'Licence issued: ' . $offer['label'] . $termText, null, [
                'offer' => ['code' => $offer['offer_code'], 'version' => (int) $offer['version']],
                'starts_at' => S::iso($startsAt), 'ends_at' => S::iso($endsAt), 'seats_total' => $seats,
                'invoice_refs' => $invoiceRefs,
            ], 1);
            if ($activate) {
                $this->event($id, $ref, $cmpOrNull, $holderOrNull, 'activated', $actor, 'Licence activated', null, ['status' => ['from' => 'issued', 'to' => 'active']], 1);
            }
            if ($holderOrNull !== null) {
                $db->query("INSERT INTO engage_holders (holder_uuid, source) VALUES (?, 'staff') ON CONFLICT (holder_uuid) DO NOTHING", [$holderOrNull]);
            }
            $row = $db->query(self::SELECT . ' WHERE l.id = ?', [$id])->getRowArray();
            $this->afterWrite([], $row, 'issue', $in, $actor);
            $db->transCommit();
        } catch (LicenceHookRejected $hx) {
            $db->transRollback();

            return $hx->result();
        } catch (\Throwable $ex) {
            $db->transRollback();
            if ($idem !== null && str_contains($ex->getMessage(), 'uq_engage_licences_idem') && ($replay = $this->replay($idem, $requestHash)) !== null) {
                return $replay; // a concurrent request with the same key won
            }
            if (str_contains($ex->getMessage(), 'uq_engage_licences_basic_live')) {
                return ['status' => 409, 'code' => 'basic_exists', 'error' => 'This holder already holds the Basic plan.'];
            }
            log_message('error', 'Licence issue failed: ' . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not issue the licence.'];
        }
        $this->audit('licence.issued', $ref, ['cmp_id' => $cmpOrNull, 'holder_uuid' => $holderOrNull, 'account_id' => $accountId, 'offer_code' => $offer['offer_code'],
            'offer_version' => (int) $offer['version'], 'activated' => $activate, 'is_addon' => $isAddon, 'is_basic' => $isBasic]);

        return ['status' => 201, 'data' => $this->staffDetail($ref)];
    }

    /** The answer for a replayed idempotency key, or null when the key is unused. */
    private function replay(string $key, string $requestHash): ?array
    {
        $prev = $this->db()->query('SELECT licence_ref, request_hash FROM engage_licences WHERE idempotency_key = ?', [$key])->getRowArray();
        if (! $prev) {
            return null;
        }
        if (! hash_equals((string) $prev['request_hash'], $requestHash)) {
            return ['status' => 409, 'code' => 'idempotency_conflict', 'error' => 'This idempotency key was already used for a different licence request.', 'details' => ['licence_ref' => $prev['licence_ref']]];
        }

        return ['status' => 200, 'data' => ($this->staffDetail($prev['licence_ref']) ?? []) + ['replayed' => true]];
    }

    /** licence_ref of a live paid licence of the product for the holder or company, else null. */
    private function liveDuplicate(?string $holder, ?int $cmpId, string $product): ?string
    {
        $or = [];
        $binds = [$product];
        if ($holder !== null) {
            $or[] = 'holder_uuid = ?';
            $binds[] = $holder;
        }
        if ($cmpId !== null) {
            $or[] = 'cmp_id = ?';
            $binds[] = $cmpId;
        }
        $r = $this->db()->query("SELECT licence_ref FROM engage_licences WHERE product_code = ? AND NOT is_basic
            AND status IN ('issued','active','suspended') AND (ends_at IS NULL OR ends_at > NOW()) AND (" . implode(' OR ', $or) . ') ORDER BY id LIMIT 1', $binds)->getRowArray();

        return $r['licence_ref'] ?? null;
    }

    /** The holder's held Basic for a product (issued / active / suspended / superseded), else null. */
    public function heldBasic(string $holder, string $product, bool $lock = false): ?array
    {
        return $this->db()->query(self::SELECT . " WHERE l.holder_uuid = ? AND l.product_code = ? AND l.is_basic
            AND l.status IN ('issued','active','suspended','superseded') ORDER BY l.id DESC LIMIT 1" . ($lock ? ' FOR UPDATE OF l' : ''), [$holder, $product])->getRowArray() ?: null;
    }

    private function holderKnown(string $holder): bool
    {
        return $this->db()->table('engage_holders')->where('holder_uuid', $holder)->countAllResults() > 0
            || $this->db()->table('engage_licences')->where('holder_uuid', $holder)->countAllResults() > 0;
    }

    public static function validHolder(string $h): bool
    {
        return preg_match('/\A[A-Za-z0-9._:\-]{1,64}\z/', $h) === 1;
    }

    /** @return array{0:?string, 1:?string} [key, error] */
    private static function idempotencyKey(array $in): array
    {
        $k = $in['idempotency_key'] ?? null;
        if ($k === null || $k === '') {
            return [null, null];
        }
        if (! is_string($k) || ! preg_match('/\A[A-Za-z0-9_.:\-]{8,128}\z/', $k)) {
            return [null, 'Idempotency-Key must be 8–128 characters of A-Z a-z 0-9 _ . : -'];
        }

        return [$k, null];
    }

    /** quote_ref (coupons, contract §4.1) is optional; when given it must look like a reference. */
    private static function quoteRefError(array $in): ?string
    {
        $q = $in['quote_ref'] ?? null;
        if ($q === null || $q === '') {
            return null;
        }

        return is_string($q) && preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $q) ? null : 'quote_ref must be a quote reference (1–64 letters, digits, _ . : -).';
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
        if (($qe = self::quoteRefError($in)) !== null) {
            return self::invalid(['quote_ref' => $qe]);
        }
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

    /**
     * Upgrade / downgrade (contract §3.1): move a live licence to another published, on-sale offer of the SAME product
     * (event plan_changed). term_mode `keep` (default) keeps ends_at; `restart` starts the new offer's term now. Seats
     * follow the new offer unless seats_total is sent. No proration is computed (that is a commercial decision staff
     * take with the invoice). A Basic licence is never changed this way — issuing the paid licence supersedes it — and
     * nothing moves a licence TO a default offer: revoking the paid licence brings Basic back.
     */
    public function changeOffer(string $ref, array $in, array $staff): array
    {
        $code = trim((string) ($in['offer_code'] ?? ''));
        $mode = (string) ($in['term_mode'] ?? 'keep');
        $e = [];
        if ($code === '') {
            $e['offer_code'] = 'Choose the offer to move to.';
        }
        if (! in_array($mode, ['keep', 'restart'], true)) {
            $e['term_mode'] = 'term_mode must be keep (same end date) or restart (the new term starts now).';
        }
        if (($qe = self::quoteRefError($in)) !== null) {
            $e['quote_ref'] = $qe;
        }
        $seatsIn = array_key_exists('seats_total', $in) && $in['seats_total'] !== '';
        if ($seatsIn && $in['seats_total'] !== null && (! is_numeric($in['seats_total']) || (int) $in['seats_total'] != $in['seats_total'] || (int) $in['seats_total'] < 0)) {
            $e['seats_total'] = 'seats_total must be 0 or more (null = no seat limit).';
        }
        if ($e !== []) {
            return self::invalid($e);
        }
        $offers = new OfferService($this->db());

        return $this->mutate($ref, $in, 'change-offer', $staff, function (array $l) use ($offers, $code, $mode, $in, $seatsIn) {
            if (EntitlementProjector::bool($l['is_basic'] ?? false)) {
                return ['status' => 409, 'code' => 'basic_not_changeable', 'error' => 'This is the holder\'s Basic plan. Issue the paid licence instead — Basic is superseded automatically while it is active.'];
            }
            if ($code === $l['offer_code']) {
                return self::invalid(['offer_code' => 'That is the current offer. Use Renew to add a term or take its newer version.']);
            }
            $offer = isset($in['offer_version']) && $in['offer_version'] !== '' && $in['offer_version'] !== null
                ? $offers->findVersion($code, (int) $in['offer_version']) : $offers->publishedVersion($code);
            if (! $offer || ! OfferService::isOnSale($offer)) {
                return self::invalid(['offer_code' => "Offer {$code} has no published version on sale."]);
            }
            if ($offer['product_code'] !== $l['product_code']) {
                return self::invalid(['offer_code' => "Choose an offer of the same product ({$l['product_code']}). A different product is a new licence."]);
            }
            if (EntitlementProjector::bool($offer['is_default'] ?? false)) {
                return self::invalid(['offer_code' => 'That is the Basic plan. To move the holder back to Basic, revoke this licence: Basic comes back on its own.']);
            }
            $type = (new LicenceTypeService($this->db()))->findByCode($offer['licence_type_code']);
            if (! $type || ! in_array($type['active'], [true, 't', 'true', 1, '1'], true)) {
                return self::invalid(['offer_code' => 'The licence type of that offer is inactive.']);
            }
            $hasTerm = (int) $offer['term_months'] > 0 || (int) $offer['term_days'] > 0;
            if ($mode === 'restart') {
                $newEnd = $hasTerm ? S::addTerm(gmdate('Y-m-d H:i:s') . '+00', (int) $offer['term_months'], (int) $offer['term_days']) : null;
            } else {
                if ($l['ends_at'] === null && $hasTerm) {
                    return self::invalid(['term_mode' => 'This licence has no end date. Choose "restart" so the new offer\'s term starts now.']);
                }
                $newEnd = $l['ends_at'];
            }
            $oldSeats = $l['seats_total'] !== null ? (int) $l['seats_total'] : null;
            $newSeats = $seatsIn ? ($in['seats_total'] === null ? null : (int) $in['seats_total']) : ($offer['seats'] !== null ? (int) $offer['seats'] : null);
            if ($newSeats !== null && $newSeats < (int) $l['seats_assigned']) {
                return ['status' => 409, 'code' => 'seats_in_use', 'error' => "{$l['seats_assigned']} seats are assigned; the new plan allows {$newSeats}. Release some first or send seats_total."];
            }
            $old = S::json($l['offer_snapshot']);
            $oldPrice = (int) ($old['price_paise'] ?? 0);
            $direction = (int) $offer['price_paise'] > $oldPrice ? 'upgrade' : ((int) $offer['price_paise'] < $oldPrice ? 'downgrade' : 'change');
            $newKeys = S::fromJsonArray($offer['app_keys_json']);
            $changes = [
                'offer' => [
                    'from' => ['code' => $l['offer_code'], 'version' => (int) $l['offer_version'], 'label' => $old['label'] ?? $l['offer_code']],
                    'to'   => ['code' => $offer['offer_code'], 'version' => (int) $offer['version'], 'label' => $offer['label']],
                ],
                'direction' => $direction,
                'term_mode' => $mode,
            ];
            if (S::iso($newEnd) !== S::iso($l['ends_at'])) {
                $changes['ends_at'] = ['from' => S::iso($l['ends_at']), 'to' => S::iso($newEnd)];
            }
            if ($newSeats !== $oldSeats) {
                $changes['seats_total'] = ['from' => $oldSeats, 'to' => $newSeats];
            }
            if ($newKeys !== S::fromJsonArray($l['app_keys_json'])) {
                $changes['app_keys'] = ['from' => S::fromJsonArray($l['app_keys_json']), 'to' => $newKeys];
            }
            $set = [
                'offer_id' => (int) $offer['id'], 'offer_code' => $offer['offer_code'], 'offer_version' => (int) $offer['version'],
                'offer_snapshot' => json_encode(OfferService::snapshot($offer)), 'plan_code' => $offer['plan_code'],
                'licence_type_code' => $offer['licence_type_code'], 'app_keys' => $newKeys, 'seats_total' => $newSeats, 'ends_at' => $newEnd,
            ];

            return [$set, [['plan_changed', 'Plan changed from ' . ($old['label'] ?? $l['offer_code']) . ' to ' . $offer['label'], $changes, self::reason($in)]]];
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
                [(int) $l['id'], $l['licence_ref'], $l['cmp_id'] !== null ? (int) $l['cmp_id'] : null, $member !== '' ? $member : null, $label !== '' ? $label : null, (int) $staff['id']]
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
        return S::strictTx($this->db(), fn () => $this->mutateTx($ref, $in, $action, $staff, $fn));
    }

    private function mutateTx(string $ref, array $in, string $action, ?array $staff, callable $fn): array
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
            $cmp = $l['cmp_id'] !== null ? (int) $l['cmp_id'] : null;
            foreach ($events as $ev) {
                $this->event((int) $l['id'], $l['licence_ref'], $cmp, $l['holder_uuid'], $ev[0], $actor, $ev[1], $ev[3] ?? null, $ev[2], $expected + 1);
            }
            $after = $db->query(self::SELECT . ' WHERE l.id = ?', [(int) $l['id']])->getRowArray();
            $this->afterWrite($l, $after, $action, $in, $actor);
            $db->transCommit();
        } catch (LicenceHookRejected $hx) {
            $db->transRollback();

            return $hx->result();
        } catch (\Throwable $ex) {
            $db->transRollback();
            log_message('error', "Licence {$action} failed for {$ref}: " . $ex->getMessage());

            return ['status' => 500, 'error' => 'Could not change the licence.'];
        }
        $meta = ['cmp_id' => $l['cmp_id'] !== null ? (int) $l['cmp_id'] : null, 'holder_uuid' => $l['holder_uuid'], 'version' => $expected + 1, 'events' => array_map(static fn ($ev) => $ev[0], $events)];
        if (! empty($events[0][3])) {
            $meta['reason'] = $events[0][3];
        }
        $this->audit('licence.' . $action, $l['licence_ref'], $meta, $staff === null);

        return ['status' => 200, 'data' => $this->staffDetail($l['licence_ref'])];
    }

    /**
     * Side effects of a licence write, INSIDE its transaction: the holder's Basic is superseded / reactivated, the
     * coupon hook runs (issue / renew / change-offer), and the subject's entitlement state is queued for Manage for
     * every app key the licence covered before or covers now.
     *
     * @param array<string,mixed> $before the row before the change ([] for a new licence)
     * @param array<string,mixed> $after  the row as written
     * @param array{kind:string,id:?int,display:string} $actor
     */
    private function afterWrite(array $before, array $after, string $action, array $in, array $actor): void
    {
        $holder = $after['holder_uuid'] ?? null;
        $keys = array_values(array_unique(array_merge($before !== [] ? EntitlementProjector::appKeysOf($before) : [], EntitlementProjector::appKeysOf($after))));
        if ($holder !== null && $holder !== '') {
            $keys = array_values(array_unique(array_merge($keys, $this->reconcileBasic($holder, (string) $after['product_code'], $actor))));
        }
        if (in_array($action, ['issue', 'renew', 'change-offer'], true)) {
            LicenceHooks::afterLicenceWrite($this->db(), $action, $after, $in);
        }
        (new EntitlementOutbox($this->db()))->enqueue($holder, $after['cmp_id'] !== null ? (int) $after['cmp_id'] : null, $keys,
            $action === 'issue' ? 'licence.issued' : 'licence.' . $action, (string) $after['licence_ref']);
    }

    /**
     * D-L1: a holder's Basic for a product is `superseded` while a paid licence of the same holder + product is active
     * (term not ended), and `active` again when none is. Runs in the caller's transaction. @return list<string> the
     * Basic's app keys when it changed, else []
     *
     * @param array{kind:string,id:?int,display:string} $actor
     */
    public function reconcileBasic(string $holder, string $product, array $actor): array
    {
        $basic = $this->db()->query(self::SELECT . " WHERE l.holder_uuid = ? AND l.product_code = ? AND l.is_basic
            AND l.status IN ('active','superseded') ORDER BY l.id DESC LIMIT 1 FOR UPDATE OF l", [$holder, $product])->getRowArray();
        if (! $basic) {
            return [];
        }
        $covering = $this->db()->query("SELECT licence_ref FROM engage_licences WHERE holder_uuid = ? AND product_code = ? AND NOT is_basic
            AND status = 'active' AND (ends_at IS NULL OR ends_at > NOW()) ORDER BY id LIMIT 1", [$holder, $product])->getRowArray();
        if ($covering && $basic['status'] === 'active') {
            $to = 'superseded';
            $set = "status = 'superseded', superseded_at = NOW()";
            $summary = 'Basic plan paused: a paid plan (' . $covering['licence_ref'] . ') applies while it is active';
        } elseif (! $covering && $basic['status'] === 'superseded') {
            $to = 'active';
            $set = "status = 'active', superseded_at = NULL";
            $summary = 'Basic plan active again (no paid plan is active)';
        } else {
            return [];
        }
        $this->db()->query("UPDATE engage_licences SET {$set}, version = version + 1, updated_at = NOW() WHERE id = ?", [(int) $basic['id']]);
        $this->event((int) $basic['id'], $basic['licence_ref'], $basic['cmp_id'] !== null ? (int) $basic['cmp_id'] : null, $holder,
            $to === 'superseded' ? 'superseded' : 'reactivated', $actor, $summary, null,
            ['status' => ['from' => $basic['status'], 'to' => $to]] + ($covering ? ['by_licence_ref' => $covering['licence_ref']] : []), (int) $basic['version'] + 1);

        return EntitlementProjector::appKeysOf($basic);
    }

    /**
     * Provisioning (contract §3.2): insert a holder's Basic licence from a default offer. MUST run inside the caller's
     * transaction, after it has checked that no Basic is held (uq_engage_licences_basic_live is the backstop).
     * Status is `superseded` straight away when a paid licence of the product is active. Returns the row.
     */
    public function insertBasic(string $holder, array $offer, string $actorDisplay): array
    {
        $db = $this->db();
        $actor = ['kind' => 'system', 'id' => null, 'display' => $actorDisplay];
        $now = gmdate('Y-m-d H:i:s') . '+00';
        $endsAt = S::addTerm($now, (int) $offer['term_months'], (int) $offer['term_days']);
        $n = (int) $db->query("SELECT nextval('engage_licence_ref_seq') AS n")->getRow()->n;
        $ref = 'LIC-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $id = (int) $db->query(
            "INSERT INTO engage_licences (licence_ref, cmp_id, holder_uuid, account_id, offer_id, offer_code, offer_version, offer_snapshot, product_code,
                licence_type_code, plan_code, app_keys, seats_total, starts_at, ends_at, status, invoice_refs, source, version, activated_at, is_basic)
             VALUES (?, NULL, ?, NULL, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::text[], ?, ?, ?, 'active', '{}', 'provisioning', 1, ?, TRUE) RETURNING id",
            [$ref, $holder, (int) $offer['id'], $offer['offer_code'], (int) $offer['version'], json_encode(OfferService::snapshot($offer)),
                $offer['product_code'], $offer['licence_type_code'], $offer['plan_code'], S::pgArray(S::fromJsonArray($offer['app_keys_json'])),
                $offer['seats'] !== null ? (int) $offer['seats'] : null, $now, $endsAt, $now]
        )->getRow()->id;
        $this->event($id, $ref, null, $holder, 'issued', $actor, 'Basic plan: ' . $offer['label'], null,
            ['offer' => ['code' => $offer['offer_code'], 'version' => (int) $offer['version']], 'starts_at' => S::iso($now), 'ends_at' => S::iso($endsAt)], 1);
        $this->event($id, $ref, null, $holder, 'activated', $actor, 'Licence activated', null, ['status' => ['from' => 'issued', 'to' => 'active']], 1);
        $this->reconcileBasic($holder, (string) $offer['product_code'], $actor);

        return $db->query(self::SELECT . ' WHERE l.id = ?', [$id])->getRowArray();
    }

    /** @param array{kind:string,id:?int,display:string} $actor */
    private function event(int $licenceId, string $ref, ?int $cmpId, ?string $holder, string $type, array $actor, string $summary, ?string $reason, array $changes, int $licenceVersion): void
    {
        $this->db()->query(
            'INSERT INTO engage_licence_events (event_id, licence_id, licence_ref, cmp_id, holder_uuid, seq, type, actor_kind, actor_id, actor_display, summary, reason, changes, licence_version)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
            ['lev_' . bin2hex(random_bytes(12)), $licenceId, $ref, $cmpId, $holder, $type, $actor['kind'], $actor['id'], $actor['display'], $summary, $reason,
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

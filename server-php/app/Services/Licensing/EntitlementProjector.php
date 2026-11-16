<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Libraries\Licensing\ManageAppRegistry;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The FULL entitlement state of one subject for one Manage app key (contract §3.3) — what Manage stores in
 * ghm_plan_entitlement. A subject is a holder (my.aicountly uuid, D-L2) for the holder's WORKSPACE licences (every
 * company they own), or a company for its COMPANY licences (docs/LICENSING.md §3.6) — a holder's licence for one
 * company, or a legacy company-only licence. A company subject is pushed with holder_uuid null and cmp_id, as legacy
 * company licences always were; `scope` says which kind of subject a push is about, and `licence_holder_uuid` who
 * holds the company licence that applies (null for a legacy one). A company licence not applied to a company yet is
 * in no subject: it grants nothing until the holder applies it.
 *
 * Which licence speaks for (subject, app) — app_keys normalised with ManageAppRegistry (drive → docs):
 *   1. an effectively active paid (non-Basic) licence; several → the one ending last (open-ended wins), then newest;
 *   2. else the holder's Basic (active, or superseded — a superseded Basic is what applies once the paid one ended,
 *      even before engage:licences-expire records it); status "active", plan.is_default true. The plan and its
 *      entitlements come from the product's CURRENT published default offer (Basic is "the product's Basic",
 *      not a frozen copy), falling back to the licence snapshot;
 *   3. else the most recently changed suspended / expired / revoked licence → that status;
 *   4. else status "none" (plan null). An issued-but-not-activated licence grants nothing yet.
 * Statuses: active | expired | revoked | suspended | none.
 */
class EntitlementProjector
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function subjectKey(?string $holderUuid, ?int $cmpId): string
    {
        return $holderUuid !== null && $holderUuid !== '' ? 'holder:' . $holderUuid : 'cmp:' . (int) $cmpId;
    }

    /**
     * The subject a licence row speaks for, as [holder_uuid, cmp_id] (one of them null) — or null for a company licence
     * not applied to a company yet, which is in no subject.
     *
     * @return array{0:?string, 1:?int}|null
     */
    public static function subjectOf(array $l): ?array
    {
        if (($l['scope'] ?? 'workspace') === 'company' || ($l['holder_uuid'] ?? null) === null || $l['holder_uuid'] === '') {
            return isset($l['cmp_id']) && $l['cmp_id'] !== null && (int) $l['cmp_id'] > 0 ? [null, (int) $l['cmp_id']] : null;
        }

        return [(string) $l['holder_uuid'], null];
    }

    /** @return list<string> Manage app keys a licence row covers */
    public static function appKeysOf(array $l): array
    {
        $out = [];
        foreach (S::fromJsonArray($l['app_keys_json'] ?? '[]') as $k) {
            $n = ManageAppRegistry::fold((string) $k);
            if (! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> the subject's licences (row lock is the caller's business) */
    public function licencesOf(?string $holderUuid, ?int $cmpId): array
    {
        $sql = 'SELECT l.*, to_jsonb(l.app_keys) AS app_keys_json FROM engage_licences l WHERE ';
        if ($holderUuid !== null && $holderUuid !== '') {
            return $this->db()->query($sql . "l.holder_uuid = ? AND l.scope = 'workspace' ORDER BY l.id", [$holderUuid])->getResultArray();
        }

        // Every company licence applied to the company: a holder's, or a legacy one with no holder.
        return $this->db()->query($sql . "l.cmp_id = ? AND l.scope = 'company' ORDER BY l.id", [(int) $cmpId])->getResultArray();
    }

    /** @return list<string> every app key any licence of the subject covers */
    public function appKeysOfSubject(?string $holderUuid, ?int $cmpId): array
    {
        $out = [];
        foreach ($this->licencesOf($holderUuid, $cmpId) as $l) {
            foreach (self::appKeysOf($l) as $k) {
                if (! in_array($k, $out, true)) {
                    $out[] = $k;
                }
            }
        }

        return $out;
    }

    /**
     * The state for (subject, app) WITHOUT event_id / seq / occurred_at (EntitlementOutbox adds them).
     *
     * @param list<array<string,mixed>>|null $licences preloaded licencesOf() rows
     * @return array<string,mixed>
     */
    public function state(?string $holderUuid, ?int $cmpId, string $appKey, ?array $licences = null): array
    {
        $appKey = ManageAppRegistry::fold($appKey);
        $licences ??= $this->licencesOf($holderUuid, $cmpId);
        $mine = array_values(array_filter($licences, static fn (array $l) => in_array($appKey, self::appKeysOf($l), true)));

        $now = time();
        $endKey = static fn (array $l): float => $l['ends_at'] === null ? INF : (float) strtotime((string) $l['ends_at']);
        $paid = array_values(array_filter($mine, static fn (array $l) => ! self::bool($l['is_basic'] ?? false) && LicenceService::effectiveStatus($l) === 'active'));
        usort($paid, static fn (array $a, array $b) => [$endKey($b), (int) $b['id']] <=> [$endKey($a), (int) $a['id']]);
        if ($paid !== []) {
            return $this->view($holderUuid, $cmpId, $appKey, $paid[0], 'active', false);
        }
        $basic = array_values(array_filter($mine, static fn (array $l) => self::bool($l['is_basic'] ?? false)
            && in_array($l['status'], ['active', 'superseded'], true) && ($l['ends_at'] === null || strtotime((string) $l['ends_at']) > $now)));
        if ($basic !== []) {
            return $this->view($holderUuid, $cmpId, $appKey, $basic[0], 'active', true);
        }
        $ended = array_values(array_filter($mine, static fn (array $l) => in_array(LicenceService::effectiveStatus($l), ['suspended', 'expired', 'revoked'], true)));
        usort($ended, static fn (array $a, array $b) => [strtotime((string) $b['updated_at']), (int) $b['id']] <=> [strtotime((string) $a['updated_at']), (int) $a['id']]);
        if ($ended !== []) {
            return $this->view($holderUuid, $cmpId, $appKey, $ended[0], LicenceService::effectiveStatus($ended[0]), false);
        }

        return $this->base($holderUuid, $cmpId, $appKey) + [
            'plan' => null, 'status' => 'none', 'licence_ref' => null, 'starts_at' => null, 'ends_at' => null,
            'seats_total' => null, 'entitlements' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function base(?string $holderUuid, ?int $cmpId, string $appKey): array
    {
        $b = ['holder_uuid' => $holderUuid !== null && $holderUuid !== '' ? $holderUuid : null, 'app_key' => $appKey];
        if ($b['holder_uuid'] === null) {
            // A company subject (additive fields): Manage resolves the company's owner itself, as it did for legacy
            // company-only licences; licence_holder_uuid names who holds the licence that applies (view()).
            $b['cmp_id'] = (int) $cmpId;
            $b['scope'] = 'company';
            $b['licence_holder_uuid'] = null;
        } else {
            $b['scope'] = 'workspace';
        }

        return $b;
    }

    /** @return array<string,mixed> */
    private function view(?string $holderUuid, ?int $cmpId, string $appKey, array $l, string $status, bool $isBasic): array
    {
        $snap = S::json($l['offer_snapshot']);
        if ($isBasic) {
            $current = (new OfferService($this->db()))->defaultFor((string) $l['product_code']);
            if ($current !== null) {
                $snap = OfferService::snapshot($current);
            }
        }
        $planCode = $snap['plan']['code'] ?? $l['plan_code'] ?? null;
        $planLabel = $snap['plan']['label'] ?? null;
        $base = $this->base($holderUuid, $cmpId, $appKey);
        if (array_key_exists('licence_holder_uuid', $base)) {
            $base['licence_holder_uuid'] = ($l['holder_uuid'] ?? null) !== '' ? ($l['holder_uuid'] ?? null) : null;
        }

        return $base + [
            'plan' => [
                'code'              => (string) ($planCode ?? $snap['offer_code'] ?? $l['offer_code']),
                'label'             => (string) ($planLabel ?? $snap['label'] ?? $l['offer_code']),
                'offer_code'        => (string) ($snap['offer_code'] ?? $l['offer_code']),
                'offer_version'     => (int) ($snap['version'] ?? $l['offer_version']),
                'licence_type_code' => (string) ($snap['licence_type']['code'] ?? $l['licence_type_code']),
                'lifecycle_kind'    => $snap['licence_type']['lifecycle_kind'] ?? null,
                'is_default'        => $isBasic,
            ],
            'status'       => $status,
            'licence_ref'  => $l['licence_ref'],
            'starts_at'    => S::iso($l['starts_at']),
            'ends_at'      => S::iso($l['ends_at']),
            'seats_total'  => $l['seats_total'] !== null ? (int) $l['seats_total'] : null,
            'entitlements' => array_values(array_map(static fn ($x) => [
                'code'  => (string) ($x['code'] ?? ''),
                'label' => (string) ($x['label'] ?? ''),
                'value' => $x['value'] ?? null,
                'unit'  => $x['unit'] ?? null,
            ], array_filter((array) ($snap['entitlements'] ?? []), 'is_array'))),
        ];
    }

    public static function bool(mixed $v): bool
    {
        return in_array($v, [true, 't', 'true', 1, '1'], true);
    }
}

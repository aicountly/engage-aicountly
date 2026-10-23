<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Basic provisioning (contract §3.2, D-L1): every signed-up my.aicountly user holds the free Basic plan of every
 * product that has a published default offer. Called by my.aicountly after sign-up
 * (POST v1/provisioning/accounts, AIC-S2S client myaicountly, scope issue) and by
 * `php spark engage:provision-basic --holder=<uuid> | --pending` for products whose default offer was published later.
 *
 * Idempotent: for each (holder_uuid, product) at most one held Basic exists (uq_engage_licences_basic_live, plus a
 * per-holder advisory lock); a repeat call changes nothing and answers the same licences. A Basic that staff REVOKED
 * is not re-issued by provisioning (staff intent wins; it is reported with status revoked). If the holder already has
 * an active paid licence for the product, the Basic is created `superseded`. Products without a default offer are
 * skipped. Each Basic written queues the holder's entitlement state for Manage in the same transaction.
 */
class ProvisioningService
{
    public const ACTOR = 'my.aicountly sign-up';

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /**
     * Validate a §3.2 body. @return array{0: ?array{holder_uuid:string,email:?string,name:?string,signed_up_at:?string,request_id:?string}, 1: array<string,string>}
     */
    public static function validate(array $in): array
    {
        $e = [];
        $holder = is_string($in['holder_uuid'] ?? null) ? trim($in['holder_uuid']) : '';
        if (! LicenceService::validHolder($holder)) {
            $e['holder_uuid'] = 'holder_uuid is required: the my.aicountly uuid (1–64 letters, digits, . _ : -).';
        }
        $email = is_string($in['email'] ?? null) ? trim($in['email']) : '';
        if ($email !== '' && (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $e['email'] = 'email is not a valid address.';
        }
        $name = is_string($in['name'] ?? null) ? trim($in['name']) : '';
        if (mb_strlen($name) > 191) {
            $name = mb_substr($name, 0, 191);
        }
        $signed = null;
        if (isset($in['signed_up_at']) && $in['signed_up_at'] !== '' && $in['signed_up_at'] !== null) {
            $signed = S::parseInstant($in['signed_up_at']);
            if ($signed === null) {
                $e['signed_up_at'] = 'signed_up_at must be an ISO-8601 time.';
            }
        }
        $rid = is_string($in['request_id'] ?? null) ? trim($in['request_id']) : '';
        if ($rid !== '' && ! preg_match('/\A[A-Za-z0-9._\-]{8,64}\z/', $rid)) {
            $e['request_id'] = 'request_id must be a uuid.';
        }
        if ($e !== []) {
            return [null, $e];
        }

        return [['holder_uuid' => $holder, 'email' => $email !== '' ? $email : null, 'name' => $name !== '' ? $name : null,
            'signed_up_at' => $signed, 'request_id' => $rid !== '' ? $rid : null], []];
    }

    /**
     * Ensure the holder's Basic licences. @return array{holder_uuid:string, licences: list<array{licence_ref:string, product_code:string, plan:array{code:string,label:string}, status:string}>, created:int}
     */
    public function provision(array $holder, string $source = 'myaicountly'): array
    {
        return S::strictTx($this->db(), fn () => $this->provisionTx($holder, $source));
    }

    private function provisionTx(array $holder, string $source): array
    {
        $db = $this->db();
        $licences = new LicenceService($db);
        $defaults = (new OfferService($db))->defaultOffers();
        $out = [];
        $created = 0;
        $db->transBegin();
        try {
            $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['provision-holder:' . $holder['holder_uuid']]);
            $db->query(
                "INSERT INTO engage_holders (holder_uuid, email, name, signed_up_at, source, last_request_id, first_provisioned_at, last_provisioned_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON CONFLICT (holder_uuid) DO UPDATE SET
                    email = COALESCE(EXCLUDED.email, engage_holders.email), name = COALESCE(EXCLUDED.name, engage_holders.name),
                    signed_up_at = COALESCE(engage_holders.signed_up_at, EXCLUDED.signed_up_at),
                    source = CASE WHEN EXCLUDED.source = 'myaicountly' THEN 'myaicountly' ELSE engage_holders.source END,
                    last_request_id = COALESCE(EXCLUDED.last_request_id, engage_holders.last_request_id),
                    first_provisioned_at = COALESCE(engage_holders.first_provisioned_at, NOW()), last_provisioned_at = NOW(), updated_at = NOW()",
                [$holder['holder_uuid'], $holder['email'] ?? null, $holder['name'] ?? null, $holder['signed_up_at'] ?? null, $source === 'staff' ? 'staff' : 'myaicountly', $holder['request_id'] ?? null]
            );
            $keys = [];
            foreach ($defaults as $offer) {
                $product = (string) $offer['product_code'];
                $row = $licences->heldBasic($holder['holder_uuid'], $product, true);
                if ($row === null) {
                    $last = $db->query('SELECT licence_ref, status FROM engage_licences WHERE holder_uuid = ? AND product_code = ? AND is_basic ORDER BY id DESC LIMIT 1',
                        [$holder['holder_uuid'], $product])->getRowArray();
                    if ($last && $last['status'] === 'revoked') {
                        $out[] = $this->item($last['licence_ref'], $product, $offer, 'revoked');

                        continue;
                    }
                    $row = $licences->insertBasic($holder['holder_uuid'], $offer, self::ACTOR);
                    $created++;
                    $keys = array_merge($keys, EntitlementProjector::appKeysOf($row));
                }
                $snap = S::json($row['offer_snapshot']);
                $out[] = $this->item($row['licence_ref'], $product, ['plan_code' => $snap['plan']['code'] ?? $row['plan_code'], 'offer_code' => $row['offer_code'],
                    'label' => $snap['plan']['label'] ?? $snap['label'] ?? $row['offer_code']], (string) $row['status']);
            }
            if ($keys !== []) {
                (new EntitlementOutbox($db))->enqueue($holder['holder_uuid'], null, $keys, 'provisioning.basic');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        return ['holder_uuid' => $holder['holder_uuid'], 'licences' => $out, 'created' => $created];
    }

    /** @return array{licence_ref:string, product_code:string, plan:array{code:string,label:string}, status:string} */
    private function item(string $ref, string $product, array $offer, string $status): array
    {
        return [
            'licence_ref'  => $ref,
            'product_code' => $product,
            'plan'         => ['code' => (string) ($offer['plan_code'] ?? $offer['offer_code']), 'label' => (string) $offer['label']],
            'status'       => $status,
        ];
    }

    /**
     * Holders my.aicountly provisioned that have never held a Basic for a product that now has a published default
     * offer (a revoked Basic counts as held — staff intent wins). Keyset-paged by holder_uuid. @return list<array<string,mixed>>
     */
    public function pendingHolders(string $after = '', int $limit = 200): array
    {
        $defaults = array_map(static fn (array $o) => (string) $o['product_code'], (new OfferService($this->db()))->defaultOffers());
        if ($defaults === []) {
            return [];
        }
        $products = S::pgArray($defaults);

        return $this->db()->query(
            "SELECT h.* FROM engage_holders h
              WHERE h.holder_uuid > ? AND h.source = 'myaicountly' AND EXISTS (
                    SELECT 1 FROM unnest(?::text[]) AS p(code)
                     WHERE NOT EXISTS (SELECT 1 FROM engage_licences l WHERE l.holder_uuid = h.holder_uuid AND l.product_code = p.code AND l.is_basic))
              ORDER BY h.holder_uuid LIMIT " . max(1, min(1000, $limit)),
            [$after, $products]
        )->getResultArray();
    }
}

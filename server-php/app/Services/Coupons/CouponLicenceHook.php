<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use CodeIgniter\Database\BaseConnection;

/**
 * Licence ↔ quote / coupon glue (docs/COUPONS.md §6). The licensing stream's App\Services\Licensing\LicenceHooks
 * calls, with the same signature, inside the licence write transaction (issue / renew / change-offer):
 *
 *     \App\Services\Coupons\CouponLicenceHook::afterLicenceWrite($db, $event, $licence, $input);
 *
 *   $db       the connection that holds the licence transaction (all writes here join it)
 *   $event    'issue' | 'renew' | 'change-offer' (also 'issued' | 'renewed' | 'plan_changed'); anything else is ignored
 *   $licence  the licence row just written: licence_ref, offer_code, and holder_uuid / cmp_id when known
 *   $input    the request body; $input['quote_ref'] (optional) and $input['invoice_refs'] / ['finance_document_ref']
 *
 * No quote_ref → nothing happens. Otherwise the quote must be open, unexpired, for the same offer (and the same holder /
 * company when both sides know it); it is marked used with the licence_ref and its reserved coupon redemption is
 * committed — in the same transaction, so the licence and the redemption commit or roll back together. Calling it
 * again for the same licence is a no-op (idempotent). Any problem throws CouponException (->reason, ->status 422):
 * the caller's transaction must roll back. Call preflight() before opening the transaction to answer a clean 422.
 *
 * Wiring on the licensing branch (LicenceHooks::afterLicenceWrite body):
 *
 *     try {
 *         \App\Services\Coupons\CouponLicenceHook::afterLicenceWrite($db, $event, $licence, $input);
 *     } catch (\App\Services\Coupons\CouponException $e) {
 *         throw new LicenceHookRejected($e->getMessage(), $e->status, 'quote_invalid', ['quote_ref' => $e->getMessage(), 'reason' => $e->reason]);
 *     }
 */
final class CouponLicenceHook
{
    /** Licence events (both the licensing branch's 'issue' | 'renew' | 'change-offer' and the event-type names). */
    private const EVENTS = ['issued' => 'new', 'issue' => 'new', 'plan_changed' => 'new', 'change_offer' => 'new', 'change-offer' => 'new',
        'renewed' => 'renewal', 'renew' => 'renewal'];

    /**
     * @param array<string,mixed> $licence
     * @param array<string,mixed> $input
     * @return array{quote_ref:string, redemption_id:?int, discount_paise:int, net_paise:int}|null
     * @throws CouponException
     */
    public static function afterLicenceWrite(BaseConnection $db, string $event, array $licence, array $input): ?array
    {
        $ref = self::quoteRef($input);
        if ($ref === null || ! isset(self::EVENTS[$event])) {
            return null;
        }
        $licenceRef = (string) ($licence['licence_ref'] ?? '');
        if ($licenceRef === '') {
            throw new CouponException('quote_mismatch', 'The licence has no licence_ref yet.');
        }
        $q = $db->query('SELECT * FROM engage_quotes WHERE quote_ref = ? FOR UPDATE', [$ref])->getRowArray();
        self::assertUsable($q, $event, $licence, $licenceRef);
        if ($q['status'] === 'used') { // same licence again: idempotent
            return ['quote_ref' => $ref, 'redemption_id' => null, 'discount_paise' => (int) $q['discount_paise'], 'net_paise' => (int) $q['net_paise']];
        }

        $redemptionId = null;
        if ($q['coupon_id'] !== null) {
            $r = $db->query('SELECT id FROM engage_coupon_redemptions WHERE quote_ref = ? ORDER BY id DESC LIMIT 1', [$ref])->getRowArray();
            if (! $r) {
                throw new CouponException('reservation_lost');
            }
            $financeRef = self::financeRef($input);
            (new RedemptionService($db))->commit((int) $r['id'], $licenceRef, $financeRef, isset($input['_actor_id']) ? (int) $input['_actor_id'] : null);
            $redemptionId = (int) $r['id'];
        }
        $ok = $db->query("UPDATE engage_quotes SET status = 'used', licence_ref = ?, used_at = NOW(), updated_at = NOW() WHERE id = ?", [$licenceRef, (int) $q['id']]);
        if ($ok === false || $db->transStatus() === false) {
            throw new CouponException('write_failed', '', 500);
        }

        return ['quote_ref' => $ref, 'redemption_id' => $redemptionId, 'discount_paise' => (int) $q['discount_paise'], 'net_paise' => (int) $q['net_paise']];
    }

    /**
     * Read-only check before the licence transaction starts. null = fine (or no quote_ref), else a service result
     * ['status' => 422, 'code' => 'quote_invalid', 'error' => …, 'details' => ['quote_ref' => …, 'reason' => …]].
     *
     * @param array<string,mixed> $licence what the licence will be: offer_code, holder_uuid / cmp_id (licence_ref unknown yet)
     */
    public static function preflight(BaseConnection $db, string $event, array $licence, array $input): ?array
    {
        $ref = self::quoteRef($input);
        if ($ref === null || ! isset(self::EVENTS[$event])) {
            return null;
        }
        try {
            $q = $db->query('SELECT * FROM engage_quotes WHERE quote_ref = ?', [$ref])->getRowArray();
            self::assertUsable($q, $event, $licence, null);
            if ($q['coupon_id'] !== null) {
                $r = $db->query('SELECT state, reserved_until FROM engage_coupon_redemptions WHERE quote_ref = ? ORDER BY id DESC LIMIT 1', [$ref])->getRowArray();
                if (! $r || $r['state'] !== 'reserved' || strtotime((string) $r['reserved_until']) <= time()) {
                    throw new CouponException('reservation_lost');
                }
            }
        } catch (CouponException $e) {
            return ['status' => 422, 'code' => 'quote_invalid', 'error' => $e->getMessage(), 'details' => ['quote_ref' => $e->getMessage(), 'reason' => $e->reason]];
        }

        return null;
    }

    private static function quoteRef(array $input): ?string
    {
        $ref = $input['quote_ref'] ?? null;
        if ($ref === null || $ref === '') {
            return null;
        }
        if (! is_string($ref) || ! preg_match('/\A[A-Z]{2,5}-\d{1,12}\z/', strtoupper(trim($ref)))) {
            throw new CouponException('quote_not_found');
        }

        return strtoupper(trim($ref));
    }

    private static function financeRef(array $input): ?string
    {
        $v = $input['finance_document_ref'] ?? null;
        if (! is_string($v) || $v === '') {
            $refs = $input['invoice_refs'] ?? null;
            $v = is_array($refs) ? ($refs[0] ?? null) : (is_string($refs) ? trim(explode(',', $refs)[0]) : null);
        }

        return is_string($v) && $v !== '' ? mb_substr($v, 0, 96) : null;
    }

    /** @throws CouponException */
    private static function assertUsable(?array $q, string $event, array $licence, ?string $licenceRef): void
    {
        if (! $q) {
            throw new CouponException('quote_not_found');
        }
        if ($q['status'] === 'used') {
            if ($licenceRef !== null && $q['licence_ref'] === $licenceRef) {
                return;
            }
            throw new CouponException('quote_used');
        }
        if ($q['status'] !== 'open' || strtotime((string) $q['expires_at']) <= time()) {
            throw new CouponException('quote_expired');
        }
        $offer = (string) ($licence['offer_code'] ?? '');
        if ($offer !== '' && $offer !== $q['offer_code']) {
            throw new CouponException('quote_mismatch', 'This quote is for offer ' . $q['offer_code'] . ', not ' . $offer . '.');
        }
        $holder = $licence['holder_uuid'] ?? null;
        if ($holder !== null && $holder !== '' && $q['holder_uuid'] !== null && $q['holder_uuid'] !== $holder) {
            throw new CouponException('quote_mismatch', 'This quote was made for another customer.');
        }
        $cmp = $licence['cmp_id'] ?? null;
        if ($cmp !== null && $cmp !== '' && $q['cmp_id'] !== null && (int) $q['cmp_id'] !== (int) $cmp) {
            throw new CouponException('quote_mismatch', 'This quote was made for another company.');
        }
        if (self::EVENTS[$event] !== $q['kind']) {
            throw new CouponException('quote_mismatch', 'This quote is for a ' . ($q['kind'] === 'renewal' ? 'renewal' : 'new purchase') . '.');
        }
    }
}

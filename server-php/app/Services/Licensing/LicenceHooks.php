<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use CodeIgniter\Database\BaseConnection;

/**
 * Extension point called INSIDE the licence write transaction (docs/LICENSING.md §"Extension points").
 *
 *   afterLicenceWrite($db, $event, $licence, $input)
 *     $event    'issue' | 'renew' | 'change-offer'
 *     $licence  the engage_licences row as written (same transaction, not yet committed)
 *     $input    the staff request as received — including an optional `quote_ref` (contract §4.1), which the
 *               licensing code validates for shape only and stores nowhere
 *
 * Runs on $db before COMMIT, so anything it writes commits or rolls back with the licence. To refuse the write,
 * throw LicenceHookRejected (the licence is rolled back and the staff call answers its status / code / message);
 * any other exception rolls back too and answers 500.
 *
 * Wired to coupons (docs/COUPONS.md): a `quote_ref` on issue / renew / change-offer commits that quote's reserved
 * redemption with this licence's licence_ref in the same transaction (CouponLicenceHook). A quote that does not fit
 * (unknown, expired, used, other holder / offer) refuses the write: 422 `quote_invalid`, details.reason = the coupon
 * reason code. Without a quote_ref nothing happens.
 */
final class LicenceHooks
{
    public static function afterLicenceWrite(BaseConnection $db, string $event, array $licence, array $input): void
    {
        try {
            \App\Services\Coupons\CouponLicenceHook::afterLicenceWrite($db, $event, $licence, $input);
        } catch (\App\Services\Coupons\CouponException $e) {
            throw new LicenceHookRejected($e->getMessage(), $e->status, 'quote_invalid', ['quote_ref' => $input['quote_ref'] ?? null, 'reason' => $e->reason]);
        }
    }
}

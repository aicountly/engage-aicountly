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
 * This is a no-op here: the coupons stream implements the redemption commit (quote_ref → committed redemption with
 * licence_ref) and integration wires it in by replacing this body.
 */
final class LicenceHooks
{
    public static function afterLicenceWrite(BaseConnection $db, string $event, array $licence, array $input): void
    {
    }
}

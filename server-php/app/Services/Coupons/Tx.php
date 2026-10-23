<?php
declare(strict_types=1);

namespace App\Services\Coupons;

use CodeIgniter\Database\BaseConnection;

/**
 * CodeIgniter 4.5+ does not throw on a failed query inside a transaction (it only marks the transaction failed and
 * transCommit() then silently rolls back). Coupon writes run with transException(true) so a failed write throws and
 * is rolled back and reported — the same approach as LicensingSupport::strictTx on the licensing branch.
 */
final class Tx
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function strict(BaseConnection $db, callable $fn): mixed
    {
        $db->transException(true);
        try {
            return $fn();
        } finally {
            $db->transException(false);
            $db->resetTransStatus();
        }
    }
}

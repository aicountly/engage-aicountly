<?php

namespace App\Commands;

use App\Services\Licensing\EntitlementOutbox;
use App\Services\Licensing\EntitlementProjector;
use App\Libraries\Licensing\CommandOptions;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark engage:entitlements-reproject [--holder=<uuid>] [--product=<code>] [--dry-run]` — re-project holders'
 * entitlement states and queue the ones that differ from what was last queued (unchanged states queue nothing).
 * Run it after publishing a NEW VERSION of a product's Basic (default) offer — Basic holders are pushed with the
 * current Basic limits — or to repair drift that engage:entitlements-reconcile reported. --dry-run counts only.
 */
class EntitlementsReproject extends BaseCommand
{
    use CommandOptions;

    protected $group       = 'Engage';
    protected $name        = 'engage:entitlements-reproject';
    protected $description = 'Re-queue holders\' entitlement states for Manage where they changed (e.g. a new Basic version).';
    protected $usage       = 'engage:entitlements-reproject [--holder=<uuid>] [--product=<code>] [--dry-run]';

    public function run(array $params)
    {
        $db = Database::connect();
        $dry = self::opt($params, 'dry-run') !== null;
        $holder = self::opt($params, 'holder');
        $product = self::opt($params, 'product');
        $where = ['holder_uuid IS NOT NULL'];
        $binds = [];
        if (is_string($holder) && $holder !== '') {
            $where[] = 'holder_uuid = ?';
            $binds[] = $holder;
        }
        if (is_string($product) && $product !== '') {
            $where[] = 'product_code = ?';
            $binds[] = $product;
        }
        $holders = array_column($db->query('SELECT DISTINCT holder_uuid FROM engage_licences WHERE ' . implode(' AND ', $where) . ' ORDER BY holder_uuid', $binds)->getResultArray(), 'holder_uuid');
        $queued = 0;
        foreach ($holders as $h) {
            $db->transException(true)->transBegin();
            try {
                $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['provision-holder:' . $h]);
                $keys = (new EntitlementProjector($db))->appKeysOfSubject($h, null);
                $queued += count((new EntitlementOutbox($db))->enqueue($h, null, $keys, 'reproject'));
                $dry ? $db->transRollback() : $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();
                CLI::error("  {$h}: " . $e->getMessage());
            } finally {
                $db->transException(false);
                $db->resetTransStatus();
            }
        }
        CLI::write(($dry ? 'Dry run: would queue ' : 'Queued ') . "{$queued} changed state(s) for " . count($holders) . ' holder(s).');

        return EXIT_SUCCESS;
    }
}

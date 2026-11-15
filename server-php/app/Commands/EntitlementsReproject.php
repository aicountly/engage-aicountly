<?php

namespace App\Commands;

use App\Services\Licensing\EntitlementOutbox;
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
        $r = EntitlementOutbox::reproject($db, is_string($holder) ? $holder : null, is_string($product) ? $product : null, $dry);
        foreach ($r['errors'] as $err) {
            CLI::error('  ' . $err);
        }
        CLI::write(($dry ? 'Dry run: would queue ' : 'Queued ') . "{$r['queued']} changed state(s) for {$r['holders']} holder(s).");

        return EXIT_SUCCESS;
    }
}

<?php

namespace App\Commands;

use App\Services\Licensing\LicenceService;
use App\Libraries\Licensing\CommandOptions;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark engage:provision-basic --holder=<uuid> | --pending [--limit=N]` (contract §3.2).
 *
 *   --holder=<uuid>  ensure that holder's Basic licence for every product with a published default offer
 *   --pending        every holder my.aicountly has provisioned who still lacks a Basic for a product whose default
 *                    offer was published after they signed up (run after publishing a new product's Basic)
 * Idempotent, like the provisioning endpoint.
 */
class ProvisionBasic extends BaseCommand
{
    use CommandOptions;

    protected $group       = 'Engage';
    protected $name        = 'engage:provision-basic';
    protected $description = 'Issue Basic licences: one holder (--holder=) or every holder still missing one (--pending).';
    protected $usage       = 'engage:provision-basic --holder=<uuid> | --pending [--limit=N]';
    protected $options     = ['--holder' => 'my.aicountly uuid', '--pending' => 'All holders missing a Basic', '--limit' => 'Max holders (pending; default 10000)'];

    public function run(array $params)
    {
        $svc = Services::provisioning();
        $holder = self::opt($params, 'holder');
        if (is_string($holder) && $holder !== '') {
            if (! LicenceService::validHolder($holder)) {
                CLI::error('--holder must be a my.aicountly uuid.');

                return EXIT_ERROR;
            }
            $r = $svc->provision(['holder_uuid' => $holder], 'staff');
            foreach ($r['licences'] as $l) {
                CLI::write("  {$l['product_code']}: {$l['licence_ref']} {$l['status']}");
            }
            CLI::write("Holder {$holder}: {$r['created']} Basic licence(s) created.");

            return EXIT_SUCCESS;
        }
        if (self::opt($params, 'pending') === null) {
            CLI::error('Give --holder=<uuid> or --pending.');

            return EXIT_ERROR;
        }
        $limit = max(1, (int) (self::opt($params, 'limit') ?? 10000));
        $after = '';
        $holders = 0;
        $created = 0;
        while ($holders < $limit) {
            $batch = $svc->pendingHolders($after, min(200, $limit - $holders));
            if ($batch === []) {
                break;
            }
            foreach ($batch as $h) {
                $r = $svc->provision(['holder_uuid' => $h['holder_uuid']]);
                $created += $r['created'];
                $holders++;
                $after = $h['holder_uuid'];
            }
        }
        CLI::write("Pending provisioning: {$holders} holder(s), {$created} Basic licence(s) created.");

        return EXIT_SUCCESS;
    }
}

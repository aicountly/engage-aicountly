<?php

namespace App\Commands;

use App\Libraries\Licensing\ManageAppRegistry;
use App\Libraries\Licensing\CommandOptions;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;

/**
 * `php spark engage:basic-offers --dry-run | --apply [--licence-type=<code>] [--create-type]` (contract §3.1).
 *
 * For every product whose code Manage knows (ManageAppRegistry; others such as `flow`, `console`, `other` are listed
 * as skipped) and that has NO default offer yet (draft or published), creates a DRAFT "Basic" offer:
 * offer_code <product>-basic, price 0, no term end, no seat limit, app_keys [Manage key], is_default, entitlements
 * EMPTY. Nothing is published: staff fill in the Basic limits (Licensing → Offers → the draft → entitlements),
 * review and Publish. Idempotent — a product that already has a default offer, or an offer code <product>-basic,
 * is left alone.
 *
 * The draft needs a complimentary licence type: --licence-type=<code>, else the only active complimentary type;
 * with none, --create-type creates `basic` (label "Basic", complimentary). --dry-run (the default) writes nothing.
 */
class BasicOffers extends BaseCommand
{
    use CommandOptions;

    protected $group       = 'Engage';
    protected $name        = 'engage:basic-offers';
    protected $description = 'Create DRAFT Basic (default) offers for products that have none (staff review and publish).';
    protected $usage       = 'engage:basic-offers [--dry-run|--apply] [--licence-type=<code>] [--create-type]';
    protected $options     = [
        '--dry-run'      => 'List what would be created (default).',
        '--apply'        => 'Create the drafts.',
        '--licence-type' => 'Complimentary licence type code to use.',
        '--create-type'  => 'Create the licence type `basic` (complimentary) when none exists.',
    ];

    public function run(array $params)
    {
        $apply = self::opt($params, 'apply') !== null && self::opt($params, 'dry-run') === null;
        $db = Database::connect();
        $typeCode = (string) (self::opt($params, 'licence-type') ?? '');
        if ($typeCode !== '') {
            $type = $db->query("SELECT code FROM engage_licence_types WHERE code = ? AND active AND lifecycle_kind = 'complimentary'", [$typeCode])->getRowArray();
            if (! $type) {
                CLI::error("Licence type {$typeCode} is not an active complimentary type.");

                return EXIT_ERROR;
            }
        } else {
            $types = $db->query("SELECT code FROM engage_licence_types WHERE active AND lifecycle_kind = 'complimentary' ORDER BY sort_order, code")->getResultArray();
            if (count($types) === 1) {
                $typeCode = $types[0]['code'];
            } elseif (count($types) > 1) {
                CLI::error('Several complimentary licence types exist (' . implode(', ', array_column($types, 'code')) . '): choose one with --licence-type=<code>.');

                return EXIT_ERROR;
            } elseif (self::opt($params, 'create-type') !== null) {
                $typeCode = 'basic';
                if ($apply) {
                    $db->query("INSERT INTO engage_licence_types (code, label, description, lifecycle_kind) VALUES ('basic', 'Basic', 'The free plan every signed-up user holds (D-L1).', 'complimentary')
                        ON CONFLICT (code) DO NOTHING");
                    Services::auditService()->log('licence_type.created', ['subject_kind' => 'licence_type', 'subject_id' => 'basic', 'actor_email' => 'system:engage', 'actor_role' => 'system',
                        'metadata' => ['by' => 'engage:basic-offers'], 'fanout_console' => true]);
                }
                CLI::write(($apply ? 'Created' : 'Would create') . ' licence type basic (complimentary).');
            } else {
                CLI::error('No active complimentary licence type. Create one (Licensing → Licence types, lifecycle "complimentary") or pass --create-type.');

                return EXIT_ERROR;
            }
        }

        $created = 0;
        foreach ($db->query('SELECT code, name FROM engage_products ORDER BY code')->getResultArray() as $p) {
            $app = ManageAppRegistry::normalize($p['code']);
            if ($app === null) {
                CLI::write("  skip {$p['code']}: not a Manage app key");

                continue;
            }
            $has = $db->query("SELECT offer_code, status FROM engage_offers WHERE product_code = ? AND is_default AND status IN ('draft','published') LIMIT 1", [$p['code']])->getRowArray();
            if ($has) {
                CLI::write("  ok   {$p['code']}: default offer {$has['offer_code']} ({$has['status']})");

                continue;
            }
            $code = $p['code'] . '-basic';
            if ($db->table('engage_offers')->where('offer_code', $code)->countAllResults() > 0) {
                CLI::write("  skip {$p['code']}: offer {$code} exists but is not marked default — mark it (Offers → Default) or rename");

                continue;
            }
            CLI::write('  ' . ($apply ? 'new ' : 'would create ') . "{$code} (draft, default) for {$p['code']} → app {$app}");
            if ($apply) {
                $db->query(
                    "INSERT INTO engage_offers (offer_code, version, product_code, licence_type_code, label, description, price_paise, currency, price_basis,
                        gst_rate, term_months, term_days, seats, app_keys, entitlements, display, status, is_default)
                     VALUES (?, 1, ?, ?, 'Basic', ?, 0, 'INR', 'inclusive', 0, 0, 0, NULL, ?::text[], '[]'::jsonb, '{}'::jsonb, 'draft', TRUE)",
                    [$code, $p['code'], $typeCode, 'Free Basic plan of ' . $p['name'] . ' — every signed-up user holds it. Set the Basic limits (entitlements), then publish.', '{' . $app . '}']
                );
                Services::auditService()->log('offer.created', ['subject_kind' => 'offer', 'subject_id' => (int) $db->insertID(), 'actor_email' => 'system:engage', 'actor_role' => 'system',
                    'metadata' => ['offer_code' => $code, 'version' => 1, 'is_default' => true, 'by' => 'engage:basic-offers'], 'fanout_console' => true]);
            }
            $created++;
        }
        CLI::write(($apply ? 'Created ' : 'Dry run: would create ') . $created . ' draft Basic offer' . ($created === 1 ? '' : 's') . '. Staff review and publish them.');

        return EXIT_SUCCESS;
    }
}

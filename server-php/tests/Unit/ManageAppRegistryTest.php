<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Licensing\EntitlementCodeCatalogue;
use App\Libraries\Licensing\ManageAppRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The static mirror of Manage's registry keys that offers' app_keys are validated against, and the entitlement-code
 * catalogue built from the fleet role files (fleet alignment 2026-10).
 *
 * @internal
 */
final class ManageAppRegistryTest extends CIUnitTestCase
{
    /** manage-aicountly server-php/src/Services/AppCatalogService.php ids, catalog order (GET /api/apps) @ f8906b6. */
    private const MANAGE_CATALOG = [
        'manage', 'books', 'hrms', 'auditor', 'fr', 'secretarial', 'vault', 'chat', 'calendar', 'docs', 'notes', 'pos',
        'billing', 'inventory', 'sales', 'purchases', 'insights', 'buddy', 'contacts', 'remote', 'advisor', 'appointments',
        'contracts', 'pay', 'crm', 'helpdesk', 'receptionist', 'valuations', 'email', 'messaging', 'voice', 'edge',
        'aspire', 'integrate', 'benefits', 'capital', 'apis', 'community',
    ];

    public function testMirrorEqualsManagesCatalogue(): void
    {
        $this->assertSame(self::MANAGE_CATALOG, ManageAppRegistry::CATALOG_KEYS);
        $all = ManageAppRegistry::all();
        sort($all);
        $want = self::MANAGE_CATALOG;
        sort($want);
        $this->assertSame($want, $all, 'APP_KEYS + COMPANY_LEVEL_KEYS + CATALOG_ONLY_KEYS = the catalog, nothing more or less');
        $this->assertCount(38, array_unique($all));
        foreach (self::MANAGE_CATALOG as $k) {
            $this->assertSame($k, ManageAppRegistry::normalize($k), $k);
            $this->assertSame($k, ManageAppRegistry::normalize(strtoupper($k)), $k);
        }
    }

    public function testPlanKeysAreExactlyWhatManageAccepts(): void
    {
        // Byte-identical to manage-aicountly server-php/tests/fixtures/manage_plan_app_keys.json, which Manage's
        // plan_app_keys_contract_test pins CompanyAppRegistry::planKeys() to (and GET v1/inbound/engage/app-keys serves).
        $shared = json_decode((string) file_get_contents(dirname(__DIR__) . '/_support/fixtures/manage_plan_app_keys.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame($shared['plan_app_keys'], ManageAppRegistry::PLAN_KEYS);
        $this->assertSame($shared['aliases'], ManageAppRegistry::ALIASES);
        $this->assertSame(['manage'], $shared['no_plan']);
        $this->assertSame([], array_values(array_diff(ManageAppRegistry::all(), ManageAppRegistry::PLAN_KEYS, $shared['no_plan'])), 'every known key has a plan, except manage');
        foreach (ManageAppRegistry::PLAN_KEYS as $k) {
            $this->assertSame($k, ManageAppRegistry::planKey($k), $k);
        }
        $this->assertSame('docs', ManageAppRegistry::planKey('Drive'));
        $this->assertNull(ManageAppRegistry::planKey('manage'), 'Manage refuses a push for manage');
        $this->assertSame('manage', ManageAppRegistry::normalize('manage'), 'still a known (catalogued) key');
        $this->assertSame([['books', 'docs'], ['manage', 'flow']], ManageAppRegistry::planKeyList(['books', 'manage', 'drive', 'flow', 'docs']));
    }

    public function testReconcileReportsAppKeyDriftAgainstManage(): void
    {
        $this->assertSame([], \App\Commands\EntitlementsReconcile::appKeyDrift(['plan_app_keys' => ManageAppRegistry::PLAN_KEYS]));
        $d = \App\Commands\EntitlementsReconcile::appKeyDrift(['plan_app_keys' => array_merge(array_diff(ManageAppRegistry::PLAN_KEYS, ['crm']), ['rocket'])]);
        $this->assertSame(['Manage accepts rocket but Engage does not (add to ManageAppRegistry::PLAN_KEYS)', 'Engage accepts crm but Manage refuses it (pushes would be 422)'], $d);
        $this->assertSame(['Manage did not list plan_app_keys'], \App\Commands\EntitlementsReconcile::appKeyDrift(null));
    }

    public function testAliasesFoldOntoManageKeys(): void
    {
        foreach (['drive' => 'docs', 'pulse' => 'buddy', 'lobby' => 'receptionist', 'connect' => 'chat', 'purchase' => 'purchases', 'ourpeople' => 'hrms'] as $alias => $key) {
            $this->assertSame($key, ManageAppRegistry::normalize($alias), $alias);
        }
        $this->assertSame([['docs', 'buddy', 'receptionist', 'chat'], ['flow']], ManageAppRegistry::normalizeList(['drive', 'docs', 'pulse', 'lobby', 'connect', 'flow']));
        foreach (['flow', 'console', 'other', 'rocket', ''] as $k) {
            $this->assertNull(ManageAppRegistry::normalize($k), $k);
        }
    }

    public function testEntitlementCodeCatalogue(): void
    {
        $codes = array_column(EntitlementCodeCatalogue::CODES, 'code');
        $this->assertSame(array_values(array_unique($codes)), $codes, 'each code once');
        $this->assertSame(['max_companies', 'max_users'], array_slice($codes, 0, 2), 'Manage-level codes first');
        foreach (EntitlementCodeCatalogue::CODES as $c) {
            $this->assertMatchesRegularExpression('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $c['code'], 'OfferService accepts the code shape');
            $this->assertContains($c['unit'], ['count', 'mb', 'bool'], $c['code']);
            $this->assertNotSame('', $c['label']);
            if ($c['app'] === null) {
                $this->assertSame('manage', $c['enforced_by']);
                $this->assertStringNotContainsString('.', $c['code']);
            } else {
                $this->assertSame($c['app'], ManageAppRegistry::normalize($c['app']), 'app is a Manage key');
                $this->assertSame('product', $c['enforced_by']);
                $prefix = explode('.', $c['code'])[0];
                $this->assertSame($c['app'], ManageAppRegistry::normalize($prefix), "{$c['code']}: prefixed with its app (drive folds onto docs)");
            }
        }
        // Every code a product published in its fleet role file (scratchpad/fleet/roles/*.json, 2026-10-23).
        foreach ([
            'hrms.max_employees', 'auditor.max_active_engagements', 'fr.max_engagements_per_year', 'fr.max_entities_per_year',
            'secretarial.max_filings_per_month', 'secretarial.max_meetings_per_month', 'vault.max_items', 'calendar.max_connected_accounts',
            'pos.max_terminals', 'pos.max_outlets', 'sales.max_quotations_per_month', 'sales.max_orders_per_month',
            'purchases.max_purchase_orders_per_month', 'insights.max_dashboards', 'insights.max_saved_reports', 'buddy.ai_questions_per_month',
            'contacts.max_contacts', 'remote.max_sessions_per_month', 'remote.max_devices', 'advisor.max_sessions_per_month',
            'advisor.max_consultations_per_month', 'appointments.max_staff', 'contracts.max_contracts', 'contracts.max_templates',
            'pay.max_payment_links_per_month', 'pay.max_api_keys', 'crm.max_customers', 'helpdesk.max_agents', 'receptionist.max_staff',
            'valuations.max_engagements_per_month', 'email.max_mailboxes', 'email.max_domains', 'messaging.max_channels',
            'messaging.max_messages_per_month', 'voice.max_numbers', 'voice.max_agents', 'integrate.max_apps',
            'benefits.max_open_applications', 'capital.max_open_applications',
            // Books, Inventory, Billing, Drive, Notes (enforced on main before the role files).
            'books.max_branches', 'books.max_vouchers_per_month', 'inventory.max_warehouses', 'inventory.max_items',
            'billing.max_bills_per_month', 'drive.storage_mb', 'notes.max_notes',
        ] as $code) {
            $this->assertTrue(EntitlementCodeCatalogue::isKnown($code), $code);
        }
        $this->assertCount(48, $codes);
        $this->assertSame('mb', EntitlementCodeCatalogue::find(' DRIVE.storage_mb ')['unit'] ?? null);
        $this->assertNull(EntitlementCodeCatalogue::find('sales.max_rockets'));
    }

    public function testCatalogueNarrowsToApps(): void
    {
        $sales = array_column(EntitlementCodeCatalogue::all(['sales']), 'code');
        $this->assertSame(['max_companies', 'max_users', 'sales.max_quotations_per_month', 'sales.max_orders_per_month'], $sales);
        $this->assertSame(['max_companies', 'max_users', 'drive.storage_mb'], array_column(EntitlementCodeCatalogue::all(['drive']), 'code'), 'drive folds onto docs');
        $this->assertSame(['max_companies', 'max_users', 'buddy.ai_questions_per_month', 'receptionist.max_staff'], array_column(EntitlementCodeCatalogue::all(['pulse', 'lobby']), 'code'));
        $this->assertSame(['max_companies', 'max_users'], array_column(EntitlementCodeCatalogue::all(['rocket']), 'code'), 'an unknown app: Manage codes only');
        $this->assertCount(count(EntitlementCodeCatalogue::CODES), EntitlementCodeCatalogue::all());
    }
}

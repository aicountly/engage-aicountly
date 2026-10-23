<?php
declare(strict_types=1);

namespace App\Libraries\Licensing;

/**
 * The entitlement codes staff can pick when they configure an offer's limits (Basic / paid): a read-only catalogue so
 * nobody has to remember or retype `sales.max_orders_per_month` (fleet alignment 2026-10). Served at
 * GET /api/v1/entitlement-codes and offered as autocomplete in Licensing → Offers → Entitlements.
 *
 * Source: each product's fleet role file (`plan_codes`, published by the product's own alignment stream — the code
 * the product enforces with 402 PLAN_LIMIT_REACHED), the codes Books / Inventory / Billing / Drive / Notes enforce on
 * main (they predate the role files), and the two codes Manage enforces itself (`max_companies`, `max_users`,
 * contract §3.6), which apply to every app. The same list is documented in manage-aicountly
 * docs/PLANS_AND_ENTITLEMENTS.md § Plan codes per application.
 *
 * Advisory only: OfferService still accepts any well-formed code (a product may add a limit before this list knows
 * it — free text stays allowed), and an absent code means unlimited.
 */
final class EntitlementCodeCatalogue
{
    /**
     * @var list<array{code:string,label:string,unit:string,app:?string,enforced_by:string}>
     *      app: the Manage app key whose product enforces it; null = Manage enforces it for every app.
     */
    public const CODES = [
        ['code' => 'max_companies', 'label' => 'Companies the account owner may own', 'unit' => 'count', 'app' => null, 'enforced_by' => 'manage'],
        ['code' => 'max_users', 'label' => 'People with access to a company in this app', 'unit' => 'count', 'app' => null, 'enforced_by' => 'manage'],
        ['code' => 'books.max_branches', 'label' => 'Branches', 'unit' => 'count', 'app' => 'books', 'enforced_by' => 'product'],
        ['code' => 'books.max_vouchers_per_month', 'label' => 'Vouchers a month', 'unit' => 'count', 'app' => 'books', 'enforced_by' => 'product'],
        ['code' => 'hrms.max_employees', 'label' => 'Employees per company', 'unit' => 'count', 'app' => 'hrms', 'enforced_by' => 'product'],
        ['code' => 'auditor.max_active_engagements', 'label' => 'Active engagements', 'unit' => 'count', 'app' => 'auditor', 'enforced_by' => 'product'],
        ['code' => 'fr.max_engagements_per_year', 'label' => 'Engagements a financial year', 'unit' => 'count', 'app' => 'fr', 'enforced_by' => 'product'],
        ['code' => 'fr.max_entities_per_year', 'label' => 'Reporting entities a financial year', 'unit' => 'count', 'app' => 'fr', 'enforced_by' => 'product'],
        ['code' => 'secretarial.max_filings_per_month', 'label' => 'Filings a month', 'unit' => 'count', 'app' => 'secretarial', 'enforced_by' => 'product'],
        ['code' => 'secretarial.max_meetings_per_month', 'label' => 'Meetings a month', 'unit' => 'count', 'app' => 'secretarial', 'enforced_by' => 'product'],
        ['code' => 'vault.max_items', 'label' => 'Credentials', 'unit' => 'count', 'app' => 'vault', 'enforced_by' => 'product'],
        ['code' => 'calendar.max_connected_accounts', 'label' => 'Connected calendar accounts', 'unit' => 'count', 'app' => 'calendar', 'enforced_by' => 'product'],
        ['code' => 'drive.storage_mb', 'label' => 'Drive storage', 'unit' => 'mb', 'app' => 'docs', 'enforced_by' => 'product'],
        ['code' => 'notes.max_notes', 'label' => 'Notes', 'unit' => 'count', 'app' => 'notes', 'enforced_by' => 'product'],
        ['code' => 'pos.max_terminals', 'label' => 'Tills in use', 'unit' => 'count', 'app' => 'pos', 'enforced_by' => 'product'],
        ['code' => 'pos.max_outlets', 'label' => 'Outlets in use', 'unit' => 'count', 'app' => 'pos', 'enforced_by' => 'product'],
        ['code' => 'billing.max_bills_per_month', 'label' => 'Bills a month', 'unit' => 'count', 'app' => 'billing', 'enforced_by' => 'product'],
        ['code' => 'inventory.max_warehouses', 'label' => 'Warehouses', 'unit' => 'count', 'app' => 'inventory', 'enforced_by' => 'product'],
        ['code' => 'inventory.max_items', 'label' => 'Items', 'unit' => 'count', 'app' => 'inventory', 'enforced_by' => 'product'],
        ['code' => 'sales.max_quotations_per_month', 'label' => 'Quotations a month', 'unit' => 'count', 'app' => 'sales', 'enforced_by' => 'product'],
        ['code' => 'sales.max_orders_per_month', 'label' => 'Sales orders a month', 'unit' => 'count', 'app' => 'sales', 'enforced_by' => 'product'],
        ['code' => 'purchases.max_purchase_orders_per_month', 'label' => 'Purchase orders a month', 'unit' => 'count', 'app' => 'purchases', 'enforced_by' => 'product'],
        ['code' => 'insights.max_dashboards', 'label' => 'Dashboards', 'unit' => 'count', 'app' => 'insights', 'enforced_by' => 'product'],
        ['code' => 'insights.max_saved_reports', 'label' => 'Saved reports', 'unit' => 'count', 'app' => 'insights', 'enforced_by' => 'product'],
        ['code' => 'buddy.ai_questions_per_month', 'label' => 'AI questions a month', 'unit' => 'count', 'app' => 'buddy', 'enforced_by' => 'product'],
        ['code' => 'contacts.max_contacts', 'label' => 'Shared contacts per company', 'unit' => 'count', 'app' => 'contacts', 'enforced_by' => 'product'],
        ['code' => 'remote.max_sessions_per_month', 'label' => 'Sessions a month', 'unit' => 'count', 'app' => 'remote', 'enforced_by' => 'product'],
        ['code' => 'remote.max_devices', 'label' => 'Registered computers', 'unit' => 'count', 'app' => 'remote', 'enforced_by' => 'product'],
        ['code' => 'advisor.max_sessions_per_month', 'label' => 'AI Advisor sessions a month', 'unit' => 'count', 'app' => 'advisor', 'enforced_by' => 'product'],
        ['code' => 'advisor.max_consultations_per_month', 'label' => 'Human Advisor consultations a month', 'unit' => 'count', 'app' => 'advisor', 'enforced_by' => 'product'],
        ['code' => 'appointments.max_staff', 'label' => 'Active team members (bookable staff)', 'unit' => 'count', 'app' => 'appointments', 'enforced_by' => 'product'],
        ['code' => 'contracts.max_contracts', 'label' => 'Open contracts per company', 'unit' => 'count', 'app' => 'contracts', 'enforced_by' => 'product'],
        ['code' => 'contracts.max_templates', 'label' => 'Contract templates per company', 'unit' => 'count', 'app' => 'contracts', 'enforced_by' => 'product'],
        ['code' => 'pay.max_payment_links_per_month', 'label' => 'Payment links and QR codes a month', 'unit' => 'count', 'app' => 'pay', 'enforced_by' => 'product'],
        ['code' => 'pay.max_api_keys', 'label' => 'Live API keys', 'unit' => 'count', 'app' => 'pay', 'enforced_by' => 'product'],
        ['code' => 'crm.max_customers', 'label' => 'Customers', 'unit' => 'count', 'app' => 'crm', 'enforced_by' => 'product'],
        ['code' => 'helpdesk.max_agents', 'label' => 'Agents', 'unit' => 'count', 'app' => 'helpdesk', 'enforced_by' => 'product'],
        ['code' => 'receptionist.max_staff', 'label' => 'Desk staff', 'unit' => 'count', 'app' => 'receptionist', 'enforced_by' => 'product'],
        ['code' => 'valuations.max_engagements_per_month', 'label' => 'Engagements a month', 'unit' => 'count', 'app' => 'valuations', 'enforced_by' => 'product'],
        ['code' => 'email.max_mailboxes', 'label' => 'Mailboxes', 'unit' => 'count', 'app' => 'email', 'enforced_by' => 'product'],
        ['code' => 'email.max_domains', 'label' => 'Domains', 'unit' => 'count', 'app' => 'email', 'enforced_by' => 'product'],
        ['code' => 'messaging.max_channels', 'label' => 'Channel connections', 'unit' => 'count', 'app' => 'messaging', 'enforced_by' => 'product'],
        ['code' => 'messaging.max_messages_per_month', 'label' => 'Outgoing messages a month', 'unit' => 'count', 'app' => 'messaging', 'enforced_by' => 'product'],
        ['code' => 'voice.max_numbers', 'label' => 'Business numbers', 'unit' => 'count', 'app' => 'voice', 'enforced_by' => 'product'],
        ['code' => 'voice.max_agents', 'label' => 'Agents', 'unit' => 'count', 'app' => 'voice', 'enforced_by' => 'product'],
        ['code' => 'integrate.max_apps', 'label' => 'Integration Apps', 'unit' => 'count', 'app' => 'integrate', 'enforced_by' => 'product'],
        ['code' => 'benefits.max_open_applications', 'label' => 'Applications in progress', 'unit' => 'count', 'app' => 'benefits', 'enforced_by' => 'product'],
        ['code' => 'capital.max_open_applications', 'label' => 'Open applications', 'unit' => 'count', 'app' => 'capital', 'enforced_by' => 'product'],
    ];

    /**
     * The catalogue, optionally narrowed to the codes that make sense for one or more apps (aliases folded: drive →
     * docs, pulse → buddy, lobby → receptionist, connect → chat): Manage's own codes plus those apps' codes.
     *
     * @param list<string> $apps
     * @return list<array{code:string,label:string,unit:string,app:?string,enforced_by:string}>
     */
    public static function all(array $apps = []): array
    {
        if ($apps === []) {
            return self::CODES;
        }
        [$keys] = ManageAppRegistry::normalizeList($apps);

        return array_values(array_filter(self::CODES, static fn (array $c): bool => $c['app'] === null || in_array($c['app'], $keys, true)));
    }

    /** @return array{code:string,label:string,unit:string,app:?string,enforced_by:string}|null */
    public static function find(string $code): ?array
    {
        $code = strtolower(trim($code));
        foreach (self::CODES as $c) {
            if ($c['code'] === $code) {
                return $c;
            }
        }

        return null;
    }

    public static function isKnown(string $code): bool
    {
        return self::find($code) !== null;
    }
}

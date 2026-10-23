<?php
declare(strict_types=1);

namespace App\Libraries\Licensing;

/**
 * Static mirror of the application keys Manage accepts for a plan / subscription (contract §3.3: an offer's
 * app_keys must be Manage registry keys = the AppCatalogService ids Manage serves at GET /api/apps). Copied from
 * manage-aicountly server-php/src/Services/CompanyAppRegistry.php (APP_KEYS + COMPANY_LEVEL_KEYS + PLAN_ONLY_KEYS +
 * ALIASES) and AppCatalogService.php, read at manage-aicountly f8906b6 (fleet registry, 23 Oct 2026).
 * tests/Unit/ManageAppRegistryTest.php pins CATALOG_KEYS (the 38 catalog ids, catalog order) and that APP_KEYS +
 * COMPANY_LEVEL_KEYS + CATALOG_ONLY_KEYS is exactly that list — when Manage adds an application, add it here in the
 * same change.
 *
 * Aliases fold a product's own name onto Manage's key: Drive is catalogued as `docs` (so `drive` and `docs` both
 * normalise to `docs`), Connect as `chat`, Pulse as `buddy`, Lobby as `receptionist`, Purchase as `purchases`.
 *
 * Manage stores a plan for every key here except `manage` itself (Manage answers 422 unknown_application to a push
 * for app_key manage: max_companies / max_users ride on each application's plan). PLAN_KEYS / planKey() are exactly
 * the keys Manage accepts for a plan (CompanyAppRegistry::planKeys(), served at GET v1/inbound/engage/app-keys):
 * offers publish, engage:basic-offers drafts and the entitlement outbox queue only those. The list is pinned to the
 * same JSON file in both repositories (tests/fixtures/manage_plan_app_keys.json here, server-php/tests/fixtures/
 * plan_app_keys.json in manage-aicountly) and checked live by engage:entitlements-reconcile.
 */
final class ManageAppRegistry
{
    /** @var list<string> Manage CompanyAppRegistry::APP_KEYS (per-application entitlements). */
    public const APP_KEYS = [
        'books', 'inventory', 'sales', 'purchases', 'billing', 'pos', 'pay', 'contacts',
        'insights', 'capital', 'valuations', 'benefits', 'contracts',
        'hrms', 'calendar', 'appointments', 'notes', 'docs', 'vault', 'chat', 'crm', 'helpdesk',
        'remote', 'messaging', 'voice', 'email', 'receptionist',
        'fr', 'secretarial', 'advisor',
        'edge', 'aspire', 'integrate', 'apis', 'buddy',
    ];

    /** @var list<string> Manage CompanyAppRegistry::COMPANY_LEVEL_KEYS. */
    public const COMPANY_LEVEL_KEYS = ['manage', 'auditor'];

    /** @var list<string> Catalogued at GET /api/apps with no company (Manage CompanyAppRegistry::PLAN_ONLY_KEYS). */
    public const CATALOG_ONLY_KEYS = ['community'];

    /** @var list<string> Manage AppCatalogService ids, in catalog (launcher) order — every product Engage licenses. */
    public const CATALOG_KEYS = [
        'manage', 'books', 'hrms', 'auditor', 'fr', 'secretarial', 'vault', 'chat', 'calendar', 'docs', 'notes', 'pos',
        'billing', 'inventory', 'sales', 'purchases', 'insights', 'buddy', 'contacts', 'remote', 'advisor', 'appointments',
        'contracts', 'pay', 'crm', 'helpdesk', 'receptionist', 'valuations', 'email', 'messaging', 'voice', 'edge',
        'aspire', 'integrate', 'benefits', 'capital', 'apis', 'community',
    ];

    /**
     * @var list<string> Keys Manage holds a plan for (CompanyAppRegistry::hasPlan): APP_KEYS + PLAN_ONLY_KEYS
     * (auditor, community). Never `manage`.
     */
    public const PLAN_KEYS = [
        'books', 'inventory', 'sales', 'purchases', 'billing', 'pos', 'pay', 'contacts',
        'insights', 'capital', 'valuations', 'benefits', 'contracts',
        'hrms', 'calendar', 'appointments', 'notes', 'docs', 'vault', 'chat', 'crm', 'helpdesk',
        'remote', 'messaging', 'voice', 'email', 'receptionist',
        'fr', 'secretarial', 'advisor',
        'edge', 'aspire', 'integrate', 'apis', 'buddy',
        'auditor', 'community',
    ];

    /** @var array<string,string> Manage CompanyAppRegistry::ALIASES. */
    public const ALIASES = [
        'drive'       => 'docs',
        'connect'     => 'chat',
        'pulse'       => 'buddy',
        'lobby'       => 'receptionist',
        'purchase'    => 'purchases',
        'smartbooks'  => 'books',
        'smart-books' => 'books',
        'ourpeople'   => 'hrms',
    ];

    /** Manage's key for $key, or null when Manage knows no such application. */
    public static function normalize(?string $key): ?string
    {
        $key = strtolower(trim((string) $key));
        if ($key === '') {
            return null;
        }
        $key = self::ALIASES[$key] ?? $key;

        return in_array($key, self::APP_KEYS, true) || in_array($key, self::COMPANY_LEVEL_KEYS, true)
            || in_array($key, self::CATALOG_ONLY_KEYS, true) ? $key : null;
    }

    /** Manage's key for $key when Manage holds a plan for it (aliases folded), else null — `manage` is null. */
    public static function planKey(?string $key): ?string
    {
        $n = self::normalize($key);

        return $n !== null && in_array($n, self::PLAN_KEYS, true) ? $n : null;
    }

    /**
     * Like normalizeList() but against PLAN_KEYS: keys Manage would refuse in an entitlement push (`manage`, unknown
     * keys) are returned apart.
     *
     * @param list<string> $keys
     * @return array{0: list<string>, 1: list<string>} [plan keys, refused]
     */
    public static function planKeyList(array $keys): array
    {
        $ok = [];
        $bad = [];
        foreach ($keys as $k) {
            $n = self::planKey((string) $k);
            if ($n === null) {
                $bad[] = (string) $k;
            } elseif (! in_array($n, $ok, true)) {
                $ok[] = $n;
            }
        }

        return [$ok, $bad];
    }

    /**
     * Normalise a list: known keys are folded and de-duplicated (order kept); unknown keys are returned apart.
     *
     * @param list<string> $keys
     * @return array{0: list<string>, 1: list<string>} [normalised, unknown]
     */
    public static function normalizeList(array $keys): array
    {
        $ok = [];
        $bad = [];
        foreach ($keys as $k) {
            $n = self::normalize((string) $k);
            if ($n === null) {
                $bad[] = (string) $k;
            } elseif (! in_array($n, $ok, true)) {
                $ok[] = $n;
            }
        }

        return [$ok, $bad];
    }

    /** Lenient: Manage's key when known, else the key as stored (lower-cased) — for reading old rows. */
    public static function fold(string $key): string
    {
        return self::normalize($key) ?? strtolower(trim($key));
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(self::APP_KEYS, self::COMPANY_LEVEL_KEYS, self::CATALOG_ONLY_KEYS);
    }
}

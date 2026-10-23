<?php
declare(strict_types=1);

namespace App\Libraries\Licensing;

/**
 * Static mirror of the application keys Manage accepts for a plan / subscription (contract §3.3: an offer's
 * app_keys must be Manage registry keys). Copied from manage-aicountly server-php/src/Services/CompanyAppRegistry.php
 * (APP_KEYS + COMPANY_LEVEL_KEYS + ALIASES, which are the AppCatalogService ids Manage serves at GET /api/apps),
 * read at manage-aicountly 515abd3 (23 Oct 2026). tests/Unit/ManageAppRegistryTest.php pins the list — when Manage
 * adds an application, add it here in the same change.
 *
 * Aliases fold a product's own name onto Manage's key: Drive is catalogued as `docs` (so `drive` and `docs` both
 * normalise to `docs`), Connect as `chat`, Pulse as `buddy`, Lobby as `receptionist`, Purchase as `purchases`.
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

        return in_array($key, self::APP_KEYS, true) || in_array($key, self::COMPANY_LEVEL_KEYS, true) ? $key : null;
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
        return array_merge(self::APP_KEYS, self::COMPANY_LEVEL_KEYS);
    }
}

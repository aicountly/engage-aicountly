<?php

namespace App\Libraries;

/**
 * Pure decision logic for Console-managed staff roles (no DB, no HTTP).
 *
 * Console (console.aicountly.org → Roles & Access) is the source of truth for
 * who may use Engage and with which role. Engage only mirrors the result into
 * engage_user_roles so RoleFilter / the SPA keep working offline from Console.
 *
 * Two inputs are understood:
 *   - the SSO exchange / introspect payload (fromIdentity)
 *   - the server-to-server user-access pull (fromUserAccess)
 *
 * Each returns a decision array:
 *   ['status' => 'grant',  'roles' => list<string>, 'superadmin' => bool,
 *    'console_user_id' => ?int, 'authz_version' => ?int]
 *   ['status' => 'deny',   'message' => string, 'revoked' => bool]
 *   ['status' => 'legacy', 'superadmin' => bool, 'console_user_id' => ?int]
 *       — Console predates role sync (no app_roles key): keep local roles,
 *         never auto-grant super_admin to a non-superadmin.
 */
final class ConsoleRoleSync
{
    /**
     * Engage role catalog (Console app_code `engage`), highest privilege first:
     * super_admin (100), sales_manager (70), seller_manager (60), kyc_reviewer (40), member (10).
     * Migration 2026-10-17-000090 upserts the engage_roles rows.
     */
    public const CATALOG = ['super_admin', 'sales_manager', 'seller_manager', 'kyc_reviewer', 'member'];

    public const SUPER_ADMIN    = 'super_admin';
    public const SALES_MANAGER  = 'sales_manager';
    public const SELLER_MANAGER = 'seller_manager';
    public const KYC_REVIEWER   = 'kyc_reviewer';
    public const MEMBER         = 'member';

    /** Role gates (Routes.php `role:` filter arguments), per the seller contract. */
    public const SELLER_ADMIN_ROLES   = [self::SUPER_ADMIN, self::SELLER_MANAGER];
    public const KYC_REVIEW_ROLES     = [self::SUPER_ADMIN, self::SELLER_MANAGER, self::KYC_REVIEWER];
    public const SELLER_VIEW_ROLES    = [self::SUPER_ADMIN, self::SALES_MANAGER, self::SELLER_MANAGER, self::KYC_REVIEWER];
    public const COMMISSION_ROLES     = [self::SUPER_ADMIN, self::SALES_MANAGER];

    public const DENY_MESSAGE    = 'Access not assigned. Ask a Console superadmin to assign you a role.';
    public const REVOKED_MESSAGE = 'Your Engage access was removed in Console. Ask a Console superadmin to assign you a role.';
    public const CONSOLE_DOWN_MESSAGE = 'Could not confirm your Engage access with Console, and your last confirmed role sync is older than 12 hours. Try again shortly.';

    /** How long locally mirrored roles stay trustworthy when Console cannot be reached. */
    public const GRACE_SECONDS = 12 * 3600;

    /**
     * @param array<string,mixed> $identity SSO exchange / introspect `data`
     * @return array<string,mixed>
     */
    public static function fromIdentity(array $identity): array
    {
        $consoleUserId = self::consoleUserId($identity);
        $global        = (bool) ($identity['global_superadmin'] ?? false);

        if (! array_key_exists('app_roles', $identity)) {
            return [
                'status'          => 'legacy',
                'superadmin'      => $global,
                'console_user_id' => $consoleUserId,
            ];
        }

        $appSuper = (bool) ($identity['app_superadmin'] ?? false);
        $roles    = self::normaliseRoles($identity['app_roles'] ?? [], $appSuper);
        if ($roles === []) {
            return ['status' => 'deny', 'message' => self::DENY_MESSAGE, 'revoked' => false];
        }

        return [
            'status'          => 'grant',
            'roles'           => $roles,
            'superadmin'      => $appSuper,
            'console_user_id' => $consoleUserId,
            'authz_version'   => self::intOrNull($identity['authz_version'] ?? null, true),
        ];
    }

    /**
     * @param array<string,mixed> $data user-access pull `data`
     * @return array<string,mixed>
     */
    public static function fromUserAccess(array $data): array
    {
        $exists = (bool) ($data['exists'] ?? false);
        $active = (bool) ($data['active'] ?? false);
        $access = (bool) ($data['app_access'] ?? false);
        if (! $exists || ! $active || ! $access) {
            return ['status' => 'deny', 'message' => self::REVOKED_MESSAGE, 'revoked' => true];
        }

        $appSuper = (bool) ($data['app_superadmin'] ?? false);
        $roles    = self::normaliseRoles($data['app_roles'] ?? [], $appSuper);
        if ($roles === []) {
            return ['status' => 'deny', 'message' => self::DENY_MESSAGE, 'revoked' => true];
        }

        return [
            'status'          => 'grant',
            'roles'           => $roles,
            'superadmin'      => $appSuper,
            'console_user_id' => self::intOrNull($data['console_user_id'] ?? null),
            'authz_version'   => self::intOrNull($data['authz_version'] ?? null, true),
        ];
    }

    /**
     * Roles to hold after a login against a Console that does not send
     * app_roles yet. Existing local roles are kept; an empty set becomes
     * `member` (or `super_admin` for a Console global superadmin).
     *
     * @param list<string> $current
     * @return list<string>
     */
    public static function legacyRoles(array $current, bool $globalSuperadmin): array
    {
        $current = array_values(array_intersect(self::CATALOG, $current));
        if ($globalSuperadmin && ! in_array(self::SUPER_ADMIN, $current, true)) {
            $current[] = self::SUPER_ADMIN;
        }
        if ($current === []) {
            $current = [self::MEMBER];
        }

        return array_values(array_intersect(self::CATALOG, $current));
    }

    /** True when roles were confirmed by Console less than GRACE_SECONDS ago. */
    public static function withinGrace(?string $rolesSyncedAt, ?int $now = null): bool
    {
        if ($rolesSyncedAt === null || trim($rolesSyncedAt) === '') {
            return false;
        }
        $ts = strtotime($rolesSyncedAt);
        if ($ts === false) {
            return false;
        }
        $now ??= time();

        return ($now - $ts) < self::GRACE_SECONDS && $ts <= $now + 300;
    }

    /**
     * Keep only slugs Engage knows, in catalog order. A Console app
     * superadmin always holds super_admin.
     *
     * @return list<string>
     */
    public static function normaliseRoles(mixed $appRoles, bool $appSuperadmin): array
    {
        $given = [];
        if (is_array($appRoles)) {
            foreach ($appRoles as $r) {
                if (is_string($r)) {
                    $given[] = strtolower(trim($r));
                }
            }
        }
        if ($appSuperadmin) {
            $given[] = self::SUPER_ADMIN;
        }

        return array_values(array_intersect(self::CATALOG, array_unique($given)));
    }

    /** @param array<string,mixed> $identity */
    private static function consoleUserId(array $identity): ?int
    {
        $id = self::intOrNull($identity['console_user_id'] ?? null);
        if ($id !== null) {
            return $id;
        }
        $user = is_array($identity['user'] ?? null) ? $identity['user'] : [];

        return self::intOrNull($user['id'] ?? null);
    }

    private static function intOrNull(mixed $v, bool $allowZero = false): ?int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }
        if (! is_int($v)) {
            return null;
        }

        return ($v > 0 || ($allowZero && $v === 0)) ? $v : null;
    }
}

<?php

namespace App\Services;

use App\Libraries\ConsoleRoleSync;
use App\Models\UsersModel;
use Config\Services;

/**
 * Mirrors Console-assigned staff roles into engage_user_roles.
 *
 * Login: the SSO exchange / introspect payload carries `app_roles`; those
 * replace the local roles. A payload without `app_roles` (Console not yet
 * upgraded) keeps the local roles and never auto-grants super_admin to a
 * non-superadmin (see ConsoleRoleSync::legacyRoles).
 *
 * Refresh: when CONSOLE_API_URL + CONSOLE_API_TOKEN are set, Console's
 * user-access endpoint is asked again. Revoked → refuse and end every local
 * session (tokens_revoked_at). Console unreachable → allowed only while the
 * last confirmed sync is younger than 12h. Not configured → warning + the
 * previous behaviour (re-issue with the locally stored roles).
 *
 * Every method returns ['ok' => true, 'roles' => list<string>] or
 * ['ok' => false, 'status' => int, 'error' => string].
 */
class StaffAccessService
{
    public function __construct(private ?UsersModel $users = null)
    {
        $this->users ??= new UsersModel();
    }

    /**
     * @param array<string,mixed> $user     engage_users row
     * @param array<string,mixed> $decision ConsoleRoleSync::fromIdentity() result (grant|legacy)
     * @return array<string,mixed>
     */
    public function applyLoginDecision(array $user, array $decision): array
    {
        $userId = (int) $user['id'];

        if (($decision['status'] ?? '') === 'deny') {
            return ['ok' => false, 'status' => 403, 'error' => (string) $decision['message']];
        }

        if (($decision['status'] ?? '') === 'grant') {
            $this->replaceRoles($userId, $decision['roles']);
            $this->recordSync($userId, $decision);

            return ['ok' => true, 'roles' => $this->users->roleCodes($userId)];
        }

        // Legacy Console payload (no app_roles).
        log_message(
            'warning',
            'Console SSO payload has no app_roles; keeping local Engage roles for user ' . $userId
            . '. Upgrade Console so roles come from Console → Roles & Access.',
        );
        $current = $this->users->roleCodes($userId);
        $roles   = ConsoleRoleSync::legacyRoles($current, (bool) ($decision['superadmin'] ?? false));
        if ($roles !== array_values(array_intersect(ConsoleRoleSync::CATALOG, $current))) {
            $this->replaceRoles($userId, $roles);
        }
        $cid = $decision['console_user_id'] ?? null;
        if ($cid !== null) {
            $this->users->update($userId, ['console_user_id' => (int) $cid]);
        }

        return ['ok' => true, 'roles' => $this->users->roleCodes($userId)];
    }

    /**
     * Re-check access with Console before minting a refreshed token.
     *
     * @param array<string,mixed> $user engage_users row
     * @return array<string,mixed>
     */
    public function refresh(array $user, ?int $now = null): array
    {
        $userId = (int) $user['id'];
        if (($user['status'] ?? 'active') !== 'active') {
            return ['ok' => false, 'status' => 403, 'error' => 'Engage user account is inactive.'];
        }

        $console = Services::consoleIdentity();
        if (! $console->userAccessConfigured()) {
            log_message(
                'warning',
                'CONSOLE_API_URL / CONSOLE_API_TOKEN not set: Engage token refresh cannot re-check roles with Console. '
                . 'Production must set both.',
            );

            return ['ok' => true, 'roles' => $this->users->roleCodes($userId)];
        }

        $consoleUserId = isset($user['console_user_id']) && $user['console_user_id'] !== null
            ? (int) $user['console_user_id']
            : null;
        $result = $console->fetchUserAccess($consoleUserId, (string) ($user['email'] ?? ''));

        if (($result['status'] ?? '') !== 'ok') {
            $syncedAt = isset($user['roles_synced_at']) ? (string) $user['roles_synced_at'] : null;
            if (ConsoleRoleSync::withinGrace($syncedAt, $now)) {
                log_message('warning', 'Console user-access unreachable; using Engage roles synced at ' . $syncedAt . ' for user ' . $userId);

                return ['ok' => true, 'roles' => $this->users->roleCodes($userId)];
            }

            return ['ok' => false, 'status' => 503, 'error' => ConsoleRoleSync::CONSOLE_DOWN_MESSAGE];
        }

        $decision = ConsoleRoleSync::fromUserAccess((array) $result['data']);
        if ($decision['status'] === 'deny') {
            $this->revokeSessions($userId);

            return ['ok' => false, 'status' => 403, 'error' => (string) $decision['message']];
        }

        $this->replaceRoles($userId, $decision['roles']);
        $this->recordSync($userId, $decision);

        return ['ok' => true, 'roles' => $this->users->roleCodes($userId)];
    }

    /**
     * Replace the user's catalog roles with $roles (unknown roles are ignored).
     *
     * @param list<string> $roles
     */
    public function replaceRoles(int $userId, array $roles): void
    {
        $roles = array_values(array_intersect(ConsoleRoleSync::CATALOG, $roles));
        $db    = $this->users->db;

        $roleIds = [];
        if ($roles !== []) {
            foreach ($db->table('engage_roles')->select('id, code')->whereIn('code', $roles)->get()->getResultArray() as $r) {
                $roleIds[(string) $r['code']] = (int) $r['id'];
            }
        }

        $db->transStart();
        $db->table('engage_user_roles')->where('user_id', $userId)->delete();
        $now = date('Y-m-d H:i:s');
        foreach ($roles as $code) {
            if (! isset($roleIds[$code])) {
                log_message('error', "Engage role '{$code}' is missing from engage_roles; run php spark migrate.");

                continue;
            }
            $db->table('engage_user_roles')->insert([
                'user_id'    => $userId,
                'role_id'    => $roleIds[$code],
                'created_at' => $now,
            ]);
        }
        $db->transComplete();
    }

    /** End every Engage session of this user (JWTs issued before now stop working). */
    public function revokeSessions(int $userId): void
    {
        $this->users->update($userId, ['tokens_revoked_at' => date('Y-m-d H:i:s')]);
    }

    /** @param array<string,mixed> $decision */
    private function recordSync(int $userId, array $decision): void
    {
        $row = ['roles_synced_at' => date('Y-m-d H:i:s')];
        if (($decision['console_user_id'] ?? null) !== null) {
            $row['console_user_id'] = (int) $decision['console_user_id'];
        }
        if (($decision['authz_version'] ?? null) !== null) {
            $row['authz_version'] = (int) $decision['authz_version'];
        }
        $this->users->update($userId, $row);
    }
}

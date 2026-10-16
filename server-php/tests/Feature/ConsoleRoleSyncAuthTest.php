<?php

namespace Tests\Feature;

use App\Libraries\ConsoleRoleSync;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeConsoleIdentity;

/**
 * Engage staff roles come from Console (SSO payload at login, user-access
 * pull at refresh). Console is stubbed — no HTTP leaves the test.
 */
final class ConsoleRoleSyncAuthTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private FakeConsoleIdentity $console;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(false);
        $this->console = new FakeConsoleIdentity();
        Services::injectMock('consoleIdentity', $this->console);
    }

    protected function tearDown(): void
    {
        Services::reset(false);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function identity(array $extra = [], string $email = 'asha@aicountly.org'): array
    {
        return array_merge([
            'active'            => true,
            'global_superadmin' => false,
            'user'              => ['id' => 42, 'name' => 'Asha', 'email' => $email, 'role' => 'staff'],
            'controller_grants' => [],
            'app_code'          => 'engage',
        ], $extra);
    }

    private function login(array $identity): TestResponse
    {
        $this->console->identity = $identity;

        return $this->withBodyFormat('json')->post('v1/auth/controller-sso', ['token' => 'launch-token']);
    }

    private function json(TestResponse $r): array
    {
        return json_decode((string) $r->response()->getBody(), true) ?? [];
    }

    private function userRow(string $email = 'asha@aicountly.org'): ?array
    {
        return Database::connect('tests')->table('engage_users')->where('email', $email)->get()->getRowArray() ?: null;
    }

    private function rolesOf(int $userId): array
    {
        $rows = Database::connect('tests')->table('engage_user_roles ur')
            ->select('r.code')->join('engage_roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)->orderBy('r.code')->get()->getResultArray();

        return array_column($rows, 'code');
    }

    private function seedUser(array $roles, string $email = 'asha@aicountly.org', array $cols = []): int
    {
        $db = Database::connect('tests');
        $db->table('engage_users')->insert(array_merge([
            'email' => $email, 'name' => 'Asha', 'password_hash' => 'x', 'status' => 'active',
        ], $cols));
        $id = (int) $db->insertID();
        foreach ($roles as $code) {
            $roleId = (int) $db->table('engage_roles')->where('code', $code)->get()->getRow()->id;
            $db->table('engage_user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return $id;
    }

    private function tokenFor(int $userId, string $email = 'asha@aicountly.org'): string
    {
        return Services::jwt()->issue($userId, $email, $this->rolesOf($userId));
    }

    private function refresh(string $token): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/auth/refresh', ['token' => $token]);
    }

    private function getWith(string $token, string $path): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get($path);
    }

    // ------------------------------------------------------------------ login

    public function testMigrationSeedsMemberRole(): void
    {
        $codes = array_column(Database::connect('tests')->table('engage_roles')->get()->getResultArray(), 'code');
        $this->assertContains('super_admin', $codes);
        $this->assertContains('member', $codes);
    }

    public function testLoginReplacesLocalRolesWithConsoleAppRoles(): void
    {
        // Was auto-granted super_admin by the old code path.
        $id = $this->seedUser(['super_admin']);

        $r = $this->login($this->identity([
            'console_user_id' => 42, 'app_roles' => ['member'], 'app_superadmin' => false, 'authz_version' => 5,
        ]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $body = $this->json($r);
        $this->assertSame(['member'], $body['data']['user']['roles']);
        $this->assertSame(['member'], $this->rolesOf($id));

        $row = $this->userRow();
        $this->assertSame(42, (int) $row['console_user_id']);
        $this->assertSame(5, (int) $row['authz_version']);
        $this->assertNotEmpty($row['roles_synced_at']);

        $payload = Services::jwt()->decode($body['data']['token']);
        $this->assertSame(['member'], $payload['roles']);
    }

    public function testEmptyAppRolesIsRefusedWith403AndNothingProvisioned(): void
    {
        $r = $this->login($this->identity(['app_roles' => [], 'app_superadmin' => false]));

        $this->assertSame(403, $r->response()->getStatusCode());
        $this->assertSame(ConsoleRoleSync::DENY_MESSAGE, $this->json($r)['error']);
        $this->assertNull($this->userRow());
    }

    public function testEmptyAppRolesStripsAnExistingUsersAccess(): void
    {
        $id = $this->seedUser(['super_admin']);

        $r = $this->login($this->identity(['app_roles' => [], 'app_superadmin' => false]));

        $this->assertSame(403, $r->response()->getStatusCode());
        // Local roles are untouched by a refused login, but no token was minted.
        $this->assertArrayNotHasKey('data', $this->json($r));
        $this->assertSame(['super_admin'], $this->rolesOf($id));
    }

    public function testBrowserSsoCallbackShowsTheAccessNotAssignedMessage(): void
    {
        $this->console->identity = $this->identity(['app_roles' => [], 'app_superadmin' => false]);

        $r = $this->get('v1/auth/sso-callback?token=launch-token');

        $this->assertSame(403, $r->response()->getStatusCode());
        $this->assertStringContainsString(ConsoleRoleSync::DENY_MESSAGE, (string) $r->response()->getBody());
    }

    public function testAppSuperadminGetsSuperAdmin(): void
    {
        $r = $this->login($this->identity(['app_roles' => [], 'app_superadmin' => true, 'global_superadmin' => true]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['super_admin'], $this->json($r)['data']['user']['roles']);
    }

    public function testOldConsolePayloadGivesNewNonSuperadminMemberNotSuperAdmin(): void
    {
        $r = $this->login($this->identity()); // no app_roles key

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['member'], $this->json($r)['data']['user']['roles']);
        $row = $this->userRow();
        $this->assertNull($row['roles_synced_at'], 'legacy login is not a Console-confirmed sync');
    }

    public function testOldConsolePayloadGlobalSuperadminGetsSuperAdmin(): void
    {
        $r = $this->login($this->identity(['global_superadmin' => true]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['super_admin'], $this->json($r)['data']['user']['roles']);
    }

    public function testOldConsolePayloadKeepsExistingLocalRoles(): void
    {
        $id = $this->seedUser(['member']);

        $r = $this->login($this->identity());

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['member'], $this->rolesOf($id));
    }

    public function testMemberCannotWriteAdministratorOnlySettings(): void
    {
        $id    = $this->seedUser(['member']);
        $token = $this->tokenFor($id);

        $this->assertSame(200, $this->getWith($token, 'v1/me')->response()->getStatusCode());

        $put = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->put('v1/settings', ['key' => 'x', 'value' => 'y']);
        $this->assertSame(403, $put->response()->getStatusCode());

        $bot = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->put('v1/bot/settings', ['mode' => 'auto']);
        $this->assertSame(403, $bot->response()->getStatusCode());
        $this->assertSame('Forbidden — insufficient role.', $this->json($bot)['error']);

        // The administrator passes the same gate.
        $adminId = $this->seedUser(['super_admin'], 'admin@aicountly.org');
        $admin   = $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($adminId, 'admin@aicountly.org')])
            ->withBodyFormat('json')->put('v1/settings', ['key' => 'x', 'value' => 'y']);
        $this->assertNotSame(403, $admin->response()->getStatusCode());
    }

    public function testJwtFilterUsesLiveRolesNotTokenSnapshot(): void
    {
        $id    = $this->seedUser(['super_admin']);
        $token = $this->tokenFor($id); // token says super_admin

        Services::staffAccess(false)->replaceRoles($id, ['member']);

        $put = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->put('v1/settings', ['key' => 'x', 'value' => 'y']);
        $this->assertSame(403, $put->response()->getStatusCode());
    }

    // ------------------------------------------------------------------ refresh

    public function testRefreshResyncsRolesFromConsole(): void
    {
        $id    = $this->seedUser(['member'], cols: ['console_user_id' => 42]);
        $token = $this->tokenFor($id);
        $this->console->pullResult = ['status' => 'ok', 'data' => [
            'exists' => true, 'active' => true, 'app_access' => true, 'app_superadmin' => false,
            'console_user_id' => 42, 'email' => 'asha@aicountly.org', 'name' => 'Asha',
            'app_roles' => ['super_admin'], 'authz_version' => 9,
        ]];

        $r = $this->refresh($token);

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['super_admin'], $this->rolesOf($id));
        $this->assertSame(['super_admin'], Services::jwt()->decode($this->json($r)['data']['token'])['roles']);
        $this->assertSame(42, $this->console->pullCalls[0]['console_user_id']);
        $this->assertSame(9, (int) $this->userRow()['authz_version']);
    }

    public function testRefreshRevokedInConsoleIsRefusedAndEndsSessions(): void
    {
        $id    = $this->seedUser(['super_admin'], cols: ['console_user_id' => 42]);
        $token = $this->tokenFor($id);
        $this->assertSame(200, $this->getWith($token, 'v1/me')->response()->getStatusCode());

        $this->console->pullResult = ['status' => 'ok', 'data' => [
            'exists' => true, 'active' => true, 'app_access' => false, 'app_superadmin' => false,
            'console_user_id' => 42, 'app_roles' => [], 'authz_version' => 10,
        ]];

        $r = $this->refresh($token);
        $this->assertSame(403, $r->response()->getStatusCode());
        $this->assertNotEmpty($this->userRow()['tokens_revoked_at']);

        // The still-unexpired JWT no longer works anywhere.
        $this->assertSame(401, $this->getWith($token, 'v1/me')->response()->getStatusCode());
    }

    public function testRefreshConsoleDownWithinGraceIsAllowed(): void
    {
        $id    = $this->seedUser(['member'], cols: ['roles_synced_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $token = $this->tokenFor($id);
        $this->console->pullResult = ['status' => 'unreachable'];

        $r = $this->refresh($token);

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['member'], $this->json($r)['data']['roles']);
    }

    public function testRefreshConsoleDownAfterGraceIsRefused(): void
    {
        $id    = $this->seedUser(['member'], cols: ['roles_synced_at' => date('Y-m-d H:i:s', time() - 13 * 3600)]);
        $token = $this->tokenFor($id);
        $this->console->pullResult = ['status' => 'unreachable'];

        $r = $this->refresh($token);

        $this->assertSame(503, $r->response()->getStatusCode());
        // Console being down is not a revocation: the current session survives.
        $this->assertEmpty($this->userRow()['tokens_revoked_at']);
    }

    public function testRefreshConsoleDownNeverSyncedIsRefused(): void
    {
        $id    = $this->seedUser(['member']);
        $token = $this->tokenFor($id);
        $this->console->pullResult = ['status' => 'unreachable'];

        $this->assertSame(503, $this->refresh($token)->response()->getStatusCode());
    }

    public function testRefreshWithoutConsoleConfigKeepsOldBehaviour(): void
    {
        $id    = $this->seedUser(['member']);
        $token = $this->tokenFor($id);
        $this->console->pullConfigured = false;

        $r = $this->refresh($token);

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame(['member'], $this->json($r)['data']['roles']);
        $this->assertSame([], $this->console->pullCalls);
    }
}

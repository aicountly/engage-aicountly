<?php

namespace Tests\Unit;

use App\Libraries\ConsoleRoleSync;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure role-decision rules for Console-managed Engage roles (no DB, no HTTP).
 */
final class ConsoleRoleSyncTest extends CIUnitTestCase
{
    private function identity(array $extra = []): array
    {
        return array_merge([
            'active'            => true,
            'global_superadmin' => false,
            'user'              => ['id' => 42, 'name' => 'Asha', 'email' => 'asha@aicountly.org', 'role' => 'staff'],
            'controller_grants' => [],
            'app_code'          => 'engage',
        ], $extra);
    }

    public function testAppRolesAreGrantedAsSent(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity([
            'console_user_id' => 42, 'app_roles' => ['member'], 'app_superadmin' => false, 'authz_version' => 7,
        ]));

        $this->assertSame('grant', $d['status']);
        $this->assertSame(['member'], $d['roles']);
        $this->assertSame(42, $d['console_user_id']);
        $this->assertSame(7, $d['authz_version']);
    }

    public function testEmptyAppRolesWithoutSuperadminIsDenied(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => [], 'app_superadmin' => false]));

        $this->assertSame('deny', $d['status']);
        $this->assertSame('Access not assigned. Ask a Console superadmin to assign you a role.', $d['message']);
    }

    public function testUnknownSlugsAloneAreDenied(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => ['reach_admin'], 'app_superadmin' => false]));

        $this->assertSame('deny', $d['status']);
    }

    public function testAppSuperadminAlwaysHoldsSuperAdmin(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => [], 'app_superadmin' => true]));

        $this->assertSame('grant', $d['status']);
        $this->assertSame(['super_admin'], $d['roles']);
    }

    public function testAuthzVersionZeroIsKept(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => ['member'], 'authz_version' => 0]));

        $this->assertSame(0, $d['authz_version']);
    }

    public function testPayloadWithoutAppRolesIsLegacy(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity());

        $this->assertSame('legacy', $d['status']);
        $this->assertFalse($d['superadmin']);
        $this->assertSame(42, $d['console_user_id']);
    }

    public function testLegacyNeverAutoGrantsSuperAdminToNonSuperadmin(): void
    {
        $this->assertSame(['member'], ConsoleRoleSync::legacyRoles([], false));
        $this->assertSame(['member'], ConsoleRoleSync::legacyRoles(['member'], false));
        $this->assertSame(['super_admin'], ConsoleRoleSync::legacyRoles([], true));
        // Existing local roles are kept as they are.
        $this->assertSame(['super_admin'], ConsoleRoleSync::legacyRoles(['super_admin'], false));
    }

    public function testUserAccessRevokedOrNoAccessIsDeniedAndRevokes(): void
    {
        foreach ([
            ['exists' => false, 'active' => true, 'app_access' => true, 'app_roles' => ['member']],
            ['exists' => true, 'active' => false, 'app_access' => true, 'app_roles' => ['member']],
            ['exists' => true, 'active' => true, 'app_access' => false, 'app_roles' => ['member']],
            ['exists' => true, 'active' => true, 'app_access' => true, 'app_roles' => [], 'app_superadmin' => false],
        ] as $data) {
            $d = ConsoleRoleSync::fromUserAccess($data);
            $this->assertSame('deny', $d['status']);
            $this->assertTrue($d['revoked']);
        }
    }

    public function testUserAccessGrant(): void
    {
        $d = ConsoleRoleSync::fromUserAccess([
            'exists' => true, 'active' => true, 'app_access' => true, 'app_superadmin' => false,
            'console_user_id' => 42, 'app_roles' => ['super_admin', 'member'], 'authz_version' => 3,
        ]);

        $this->assertSame('grant', $d['status']);
        $this->assertSame(['super_admin', 'member'], $d['roles']);
        $this->assertSame(3, $d['authz_version']);
    }

    public function testGraceWindowIsTwelveHours(): void
    {
        $now = 1_800_000_000;
        $this->assertTrue(ConsoleRoleSync::withinGrace(date('Y-m-d H:i:s', $now - 3600), $now));
        $this->assertTrue(ConsoleRoleSync::withinGrace(date('Y-m-d H:i:s', $now - (12 * 3600 - 60)), $now));
        $this->assertFalse(ConsoleRoleSync::withinGrace(date('Y-m-d H:i:s', $now - (12 * 3600 + 60)), $now));
        $this->assertFalse(ConsoleRoleSync::withinGrace(null, $now));
        $this->assertFalse(ConsoleRoleSync::withinGrace('', $now));
    }
}

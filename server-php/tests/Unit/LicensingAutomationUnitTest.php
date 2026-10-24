<?php

namespace Tests\Unit;

use App\Commands\EntitlementsReconcile;
use App\Libraries\AicS2s\AicS2sConfig;
use App\Libraries\AicS2s\AicS2sSignature;
use App\Libraries\AicS2s\ManageClient;
use App\Libraries\Licensing\ManageAppRegistry;
use App\Services\Licensing\OfferService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Database-free parts of licensing automation: Manage registry mirror, outbound AIC-S2S signing vectors (the same
 * vectors my.aicountly's port pins), jobs.txt scheduling, Finance SAC rule, reconcile diff.
 *
 * @internal
 */
final class LicensingAutomationUnitTest extends CIUnitTestCase
{
    private const SECRET = 'example-secret-do-not-use-0123456789abcdef';
    private const TS = 1792310400;
    private const NONCE = '5b1f3c9e-8d4a-4f2b-9c6e-2a7d1e0f4b3c';

    public function testManageRegistryMirrorIsPinned(): void
    {
        // manage-aicountly server-php/src/Services/CompanyAppRegistry.php @ f7f7535 — update both together
        // (the whole catalogue is pinned in ManageAppRegistryTest). No edge / aspire: Aicountly Academy courses,
        // never licensed (ManageAppRegistry::COURSE_KEYS, Manage CompanyAppRegistry::ACADEMY_COURSE_KEYS).
        $this->assertCount(33, ManageAppRegistry::APP_KEYS);
        $this->assertSame([], array_intersect(ManageAppRegistry::COURSE_KEYS, ManageAppRegistry::APP_KEYS));
        $this->assertSame(['manage', 'auditor'], ManageAppRegistry::COMPANY_LEVEL_KEYS);
        foreach (['books', 'inventory', 'billing', 'notes', 'docs', 'chat', 'buddy', 'receptionist', 'purchases'] as $k) {
            $this->assertSame($k, ManageAppRegistry::normalize($k));
        }
        $this->assertSame('docs', ManageAppRegistry::normalize('drive'));
        $this->assertSame('docs', ManageAppRegistry::normalize(' Docs '));
        $this->assertSame('hrms', ManageAppRegistry::normalize('ourpeople'));
        $this->assertNull(ManageAppRegistry::normalize('flow'));
        $this->assertNull(ManageAppRegistry::normalize(''));
        $this->assertSame([['docs', 'books'], ['console']], ManageAppRegistry::normalizeList(['drive', 'docs', 'books', 'console']));
        $this->assertSame('flow', ManageAppRegistry::fold('Flow'));
    }

    public function testOutboundEntitlementSignatureVector(): void
    {
        // printf 'POST\n/v1/inbound/engage/entitlements\n\n1792310400\n<nonce>\n<sha256(body)>' | openssl dgst -sha256 -hmac <secret>
        $body = '{"holder_uuid":"10042","app_key":"books","seq":17}';
        $c = AicS2sSignature::canonical('POST', '/api/v1/inbound/engage/entitlements', '', (string) self::TS, self::NONCE, $body);
        $this->assertSame("POST\n/v1/inbound/engage/entitlements\n\n1792310400\n" . self::NONCE . "\n8c99c2a5434316b7168e95dd49ba3e6baa60daca8e0f938b889a2d455e313ca1", $c);
        $this->assertSame('v1=f35ab9fbaab491930b0d8aaad6bf1e5b7b72beef5e5985ce113c22def78946bd', AicS2sSignature::sign(self::SECRET, $c));
    }

    public function testProvisioningSignatureVector(): void
    {
        $body = '{"holder_uuid":"10042","email":"asha@example.com","name":"Asha","signed_up_at":"2026-10-23T10:00:00Z","request_id":"0b6f3c1e-1d2a-4c3b-9e8f-7a6b5c4d3e2f"}';
        $h = AicS2sSignature::headers('myaicountly', 'myaicountly-issue-2026-10', self::SECRET, 'sandbox', 'POST', '/v1/provisioning/accounts', '', $body, 'service', self::TS, self::NONCE);
        $this->assertSame('v1=695decf3d4653d54b7af5e1ca810b7cd0675487d65e5f4f7d52bb6da52c9e7ea', $h['X-AIC-Signature']);
        $this->assertSame('myaicountly', $h['X-AIC-Client']);
    }

    public function testManageClientSignsWhatItSendsAndIsDormantUnconfigured(): void
    {
        $seen = null;
        $client = new ManageClient(static function (string $m, string $url, array $headers, string $body) use (&$seen) {
            $seen = compact('m', 'url', 'headers', 'body');

            return ['status' => 200, 'body' => '{"success":true}', 'error' => ''];
        }, 'https://manage.example/api', 'engage-push-1', str_repeat('s', 32), 'production');
        $this->assertTrue($client->isConfigured());
        $res = $client->post(ManageClient::ENTITLEMENTS_PATH, ['seq' => 1, 'holder_uuid' => 'u/1'], 'req-12345678');
        $this->assertSame(200, $res['status']);
        $this->assertSame('https://manage.example/api/v1/inbound/engage/entitlements', $seen['url']);
        $h = [];
        foreach ($seen['headers'] as $line) {
            [$k, $v] = explode(': ', $line, 2);
            $h[$k] = $v;
        }
        $this->assertSame('engage', $h['X-AIC-Client']);
        $this->assertSame('production', $h['X-AIC-Environment']);
        $this->assertSame('req-12345678', $h['X-AIC-Request-Id']);
        $this->assertSame('{"seq":1,"holder_uuid":"u/1"}', $seen['body']);
        $this->assertTrue(AicS2sSignature::verify(str_repeat('s', 32), AicS2sSignature::canonical('POST', '/v1/inbound/engage/entitlements', '', $h['X-AIC-Timestamp'], $h['X-AIC-Nonce'], $seen['body']), $h['X-AIC-Signature']));

        $dormant = new ManageClient(static fn () => throw new \LogicException('no HTTP'), '', 'k', str_repeat('s', 32), 'production');
        $this->assertFalse($dormant->isConfigured());
        $this->assertStringContainsString('MANAGE_API_BASE_URL', $dormant->status());
        $this->assertSame('not_configured', $dormant->post('/v1/x', [])['error']);
        $short = new ManageClient(null, 'https://m/api', 'k', 'short', 'production');
        $this->assertFalse($short->isConfigured());
        $this->assertStringNotContainsString('short', $short->status());
    }

    public function testScopeMethodsAndRateDefaults(): void
    {
        $this->assertSame(['GET', 'HEAD'], AicS2sConfig::methodsFor('read'));
        $this->assertSame(['POST'], AicS2sConfig::methodsFor('issue'));
        $this->assertSame([], AicS2sConfig::methodsFor('nope'));
        $this->assertSame(['POST'], AicS2sConfig::methodsFor('quote')); // coupons: Manage's preview / upgrade requests
        $this->assertSame(['read' => 120, 'issue' => 30, 'quote' => 60], AicS2sConfig::DEFAULT_RATE_LIMITS);
    }

    public function testJobsFileSchedulesLicensingJobs(): void
    {
        $jobs = [];
        foreach (file(ROOTPATH . 'bin/ops/jobs.txt', FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $this->assertCount(3, $parts, $line);
            $jobs[$parts[0]] = [$parts[1], $parts[2]];
        }
        $this->assertSame(['23 * * * *', '{php} spark engage:licences-expire'], $jobs['licences-expire']);
        $this->assertSame(['* * * * *', '{php} spark engage:entitlements-push'], $jobs['entitlements-push']);
        $this->assertSame('{php} spark engage:entitlements-reconcile', $jobs['entitlements-reconcile'][1]);
        $this->assertArrayHasKey('console-audit-deliver', $jobs);
        $this->assertStringContainsString('#! legacy-pattern: spark[[:space:]]+engage:licences-expire', (string) file_get_contents(ROOTPATH . 'bin/ops/jobs.txt'));
    }

    public function testFinanceSacRule(): void
    {
        $this->assertTrue(OfferService::financeSac('997331'));
        $this->assertFalse(OfferService::financeSac('9983'));
        $this->assertFalse(OfferService::financeSac('99833112'));
        $this->assertFalse(OfferService::financeSac('847130'));
    }

    public function testReconcileDiff(): void
    {
        $mine = ['status' => 'active', 'plan' => ['code' => 'books-pro'], 'licence_ref' => 'LIC-000001', 'ends_at' => '2027-10-18T18:30:00Z'];
        $this->assertSame([], EntitlementsReconcile::diff($mine, $mine + ['seq' => 5], 5));
        $this->assertSame(['missing in Manage (Engage: active)'], EntitlementsReconcile::diff($mine, null, 5));
        $this->assertSame([], EntitlementsReconcile::diff(['status' => 'none'] + $mine, null, 0));
        $d = EntitlementsReconcile::diff($mine, ['status' => 'expired', 'plan' => ['code' => 'books-basic'], 'licence_ref' => 'LIC-000001', 'ends_at' => '2027-10-18T18:30:00+00:00', 'seq' => 3], 5);
        $this->assertCount(3, $d);
        $this->assertSame(['books' => ['app_key' => 'books', 'status' => 'active']], EntitlementsReconcile::byApp(['items' => [['app_key' => 'books', 'status' => 'active']]]));
        $this->assertSame(['books' => ['status' => 'active', 'app_key' => 'books']], EntitlementsReconcile::byApp(['books' => ['status' => 'active']]));
    }
}

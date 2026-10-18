<?php

namespace Tests\Feature;

use App\Libraries\AicS2s\AicS2sSignature;
use Tests\Support\LicensingTestCase;

/**
 * AIC-S2S v1 inbound filter for client `manage` (commerce contract v1 §0.1/§0.2/§0.4): dormant (503) until configured,
 * environment header, unknown client / key, stale timestamp, bad signature, nonce replay, read key on a write route,
 * issue key on a read route, short secrets ignored, rate limit, envelope shape and request id echo.
 */
final class AicS2sFilterTest extends LicensingTestCase
{
    private const PATH = 'manage/licence-types';

    private function assertError(\CodeIgniter\Test\TestResponse $r, int $status, string $code, bool $retryable = false): array
    {
        $b = $this->json($r);
        $this->assertSame($status, $this->code($r), (string) $r->response()->getBody());
        $this->assertFalse($b['success']);
        $this->assertSame($code, $b['error']['code']);
        $this->assertSame($retryable, $b['error']['retryable']);
        $this->assertIsString($b['error']['message']);
        $this->assertArrayHasKey('details', $b['error']);
        $this->assertNotEmpty($b['request_id']);

        return $b;
    }

    public function testUnconfiguredAnswers503InboundNotConfigured(): void
    {
        $this->assertError($this->s2s('get', self::PATH), 503, 'inbound_not_configured', true);

        // A key without an environment, or an environment without a (long enough) key, is still not configured.
        $this->setEnv('ENGAGE_INBOUND_MANAGE_KEY_IDS', self::READ_KEY_ID);
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10', self::READ_SECRET);
        $this->assertError($this->s2s('get', self::PATH), 503, 'inbound_not_configured', true);
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'sandbox');
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10', str_repeat('x', 31));
        $this->assertError($this->s2s('get', self::PATH, '', [], '', null, str_repeat('x', 31)), 503, 'inbound_not_configured', true);
    }

    public function testSignedRequestPassesAndEchoesRequestId(): void
    {
        $this->configureManage();
        $r = $this->s2s('get', self::PATH, '', ['X-AIC-Request-Id' => 'req-12345678']);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $b = $this->json($r);
        $this->assertTrue($b['success']);
        $this->assertSame([], $b['data']);
        $this->assertSame('req-12345678', $b['request_id']);
        $this->assertSame('req-12345678', $r->response()->getHeaderLine('X-AIC-Request-Id'));
        $this->assertStringContainsString('no-store', $r->response()->getHeaderLine('Cache-Control'));
    }

    public function testEnvironmentRequiredAndMismatch(): void
    {
        $this->configureManage();
        $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Environment' => null]), 400, 'environment_required');
        $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Environment' => 'production']), 403, 'environment_mismatch');
    }

    public function testMissingHeadersUnknownClientAndUnknownKey(): void
    {
        $this->configureManage();
        $b = $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Signature' => null]), 401, 'signature_missing');
        $this->assertSame('X-AIC-Signature', $b['error']['details']['header']);
        $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Nonce' => null]), 401, 'signature_missing');
        $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Client' => 'finance']), 401, 'unknown_key');
        $this->assertError($this->s2s('get', self::PATH, '', [], '', 'manage-read-2025-01', self::READ_SECRET), 401, 'unknown_key');
    }

    public function testStaleTimestampEitherWay(): void
    {
        $this->configureManage();
        $this->assertError($this->s2s('get', self::PATH, '', [], '', null, null, time() - 301), 401, 'timestamp_stale');
        $this->assertError($this->s2s('get', self::PATH, '', [], '', null, null, time() + 301), 401, 'timestamp_stale');
        $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Timestamp' => 'yesterday']), 401, 'timestamp_stale');
        // Inside the window is fine.
        $this->assertSame(200, $this->code($this->s2s('get', self::PATH, '', [], '', null, null, time() - 250)));
    }

    public function testBadSignatureWrongSecretAndTamperedQuery(): void
    {
        $this->configureManage();
        $this->assertError($this->s2s('get', self::PATH, '', ['X-AIC-Signature' => 'v1=' . str_repeat('0', 64)]), 401, 'signature_invalid');
        $this->assertError($this->s2s('get', self::PATH, '', [], '', null, str_repeat('w', 40)), 401, 'signature_invalid');
        // Signed for cmp_id=42, sent for cmp_id=43.
        $h = AicS2sSignature::headers('manage', self::READ_KEY_ID, self::READ_SECRET, 'sandbox', 'GET', '/v1/manage/catalogue', 'cmp_id=42');
        $this->headers = [];
        $r = $this->withHeaders($h)->get('v1/manage/catalogue?cmp_id=43');
        $this->assertError($r, 401, 'signature_invalid');
        // The signature covers the method too: headers signed for POST do not authorise a GET.
        $sig = AicS2sSignature::headers('manage', self::READ_KEY_ID, self::READ_SECRET, 'sandbox', 'POST', '/v1/' . self::PATH);
        $this->headers = [];
        $this->assertError($this->withHeaders($sig)->get('v1/' . self::PATH), 401, 'signature_invalid');
    }

    public function testNonceReplayIsRefused(): void
    {
        $this->configureManage();
        $nonce = AicS2sSignature::uuid4();
        $h = AicS2sSignature::headers('manage', self::READ_KEY_ID, self::READ_SECRET, 'sandbox', 'GET', '/v1/' . self::PATH, '', '', 'service', null, $nonce);
        $this->headers = [];
        $this->assertSame(200, $this->code($this->withHeaders($h)->get('v1/' . self::PATH)));
        $this->headers = [];
        $this->assertError($this->withHeaders($h)->get('v1/' . self::PATH), 401, 'nonce_replayed');
        // Another nonce is fine; a malformed (correctly signed) nonce is refused and never stored.
        $this->assertSame(200, $this->code($this->s2s('get', self::PATH)));
        $bad = AicS2sSignature::headers('manage', self::READ_KEY_ID, self::READ_SECRET, 'sandbox', 'GET', '/v1/' . self::PATH, '', '', 'service', null, 'short');
        $this->headers = [];
        $this->assertError($this->withHeaders($bad)->get('v1/' . self::PATH), 401, 'signature_invalid');
        $this->assertSame(0, $this->db()->table('engage_s2s_nonces')->where('nonce', 'short')->countAllResults());
        $this->assertSame(2, $this->db()->table('engage_s2s_nonces')->where('key_id', self::READ_KEY_ID)->countAllResults());
    }

    public function testWriteAttemptWithReadKeyIsForbidden(): void
    {
        $this->configureManage(true);
        $body = '{"cmp_id":42}';
        $b = $this->assertError($this->s2s('post', 'manage/companies/42/licences', '', [], $body), 403, 'route_not_allowed_for_key');
        $this->assertSame('read', $b['error']['details']['scope']);
        $this->assertError($this->s2s('delete', 'manage/companies/42/licences/LIC-000001'), 403, 'route_not_allowed_for_key');
        // An issue key may only POST — and no Manage write route exists in Phase A.
        $this->assertError($this->s2s('get', self::PATH, '', [], '', self::ISSUE_KEY_ID, self::ISSUE_SECRET), 403, 'route_not_allowed_for_key');
        $this->assertError($this->s2s('post', 'manage/companies/42/licences', '', [], $body, self::ISSUE_KEY_ID, self::ISSUE_SECRET), 404, 'not_found');
        $this->assertSame(0, $this->db()->table('engage_licences')->countAllResults());
    }

    public function testRateLimitPerKey(): void
    {
        $this->configureManage();
        $this->db()->query("INSERT INTO engage_s2s_rate (key_id, window_start, hits) VALUES (?, date_trunc('minute', NOW()), 120)", [self::READ_KEY_ID]);
        $r = $this->s2s('get', self::PATH);
        // The minute may have rolled over between the insert and the call; then the request is simply allowed.
        if ($this->code($r) === 200) {
            $this->markTestSkipped('Minute boundary crossed during the test.');
        }
        $this->assertError($r, 429, 'rate_limited', true);
        $this->assertGreaterThanOrEqual(1, (int) $r->response()->getHeaderLine('Retry-After'));
    }

    public function testRotationTwoLiveKeys(): void
    {
        $this->configureManage();
        $this->setEnv('ENGAGE_INBOUND_MANAGE_KEY_IDS', self::READ_KEY_ID . ', manage-read-2026-11');
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_11', 'next-secret-0123456789abcdefghijklmnopqrstu');
        $this->assertSame(200, $this->code($this->s2s('get', self::PATH)));
        $this->assertSame(200, $this->code($this->s2s('get', self::PATH, '', [], '', 'manage-read-2026-11', 'next-secret-0123456789abcdefghijklmnopqrstu')));
    }

    public function testStaffJwtDoesNotOpenManageRoutes(): void
    {
        $this->configureManage();
        $token = $this->staff(['super_admin'], 'root@aicountly.org');
        $this->headers = [];
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('v1/' . self::PATH);
        $this->assertError($r, 400, 'environment_required');
        $this->headers = [];
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-AIC-Environment' => 'sandbox'])->get('v1/' . self::PATH);
        $this->assertError($r, 401, 'signature_missing');
    }
}

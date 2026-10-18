<?php

namespace Tests\Unit;

use App\Libraries\AicS2s\AicS2sConfig;
use App\Libraries\AicS2s\AicS2sSignature;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AIC-S2S v1 canonical string and signature (commerce contract v1 §0.1). The worked example is the one in
 * docs/LICENSING.md (cross-checked with `openssl dgst -sha256 -hmac`).
 *
 * @internal
 */
final class AicS2sSignatureTest extends CIUnitTestCase
{
    private const SECRET = 'example-secret-do-not-use-0123456789abcdef';

    public function testWorkedExampleFromTheDocs(): void
    {
        $c = AicS2sSignature::canonical('GET', '/v1/manage/companies/42/licences', 'status=active&limit=25', '1792310400', '5b1f3c9e-8d4a-4f2b-9c6e-2a7d1e0f4b3c', '');
        $this->assertSame(
            "GET\n/v1/manage/companies/42/licences\nlimit=25&status=active\n1792310400\n5b1f3c9e-8d4a-4f2b-9c6e-2a7d1e0f4b3c\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            $c
        );
        $this->assertSame('v1=e0b4ecf7af1a52998341aa7319b716593271a93737a42e1eeab724fac67363f8', AicS2sSignature::sign(self::SECRET, $c));
        $this->assertTrue(AicS2sSignature::verify(self::SECRET, $c, 'v1=e0b4ecf7af1a52998341aa7319b716593271a93737a42e1eeab724fac67363f8'));
        $this->assertTrue(AicS2sSignature::verify(self::SECRET, $c, 'V1=E0B4ECF7AF1A52998341AA7319B716593271A93737A42E1EEAB724FAC67363F8'));
        $this->assertFalse(AicS2sSignature::verify(self::SECRET, $c, 'e0b4ecf7af1a52998341aa7319b716593271a93737a42e1eeab724fac67363f8'));
        $this->assertFalse(AicS2sSignature::verify(self::SECRET . 'x', $c, 'v1=e0b4ecf7af1a52998341aa7319b716593271a93737a42e1eeab724fac67363f8'));
    }

    public function testCanonicalQuerySortsAndReEncodes(): void
    {
        $this->assertSame('', AicS2sSignature::canonicalQuery(''));
        $this->assertSame('', AicS2sSignature::canonicalQuery('?'));
        $this->assertSame('a=1&a=x%20y&b=2&c=&d=', AicS2sSignature::canonicalQuery('b=2&a=x%20y&a=1&c=&d'));
        $this->assertSame('q=a%20b&z=~', AicS2sSignature::canonicalQuery('z=%7E&q=a+b'));
        $this->assertSame('cursor=eyJkIjoiMjAyNi0xMC0xOFQwMDowMDowMC4wMDAwMDBaIiwiaWQiOjV9', AicS2sSignature::canonicalQuery('cursor=eyJkIjoiMjAyNi0xMC0xOFQwMDowMDowMC4wMDAwMDBaIiwiaWQiOjV9'));
    }

    public function testBodyAndPathAreCovered(): void
    {
        $a = AicS2sSignature::canonical('post', 'v1/x', '', '1', 'n', '{"a":1}');
        $this->assertStringStartsWith("POST\n/v1/x\n\n1\nn\n", $a);
        $this->assertStringEndsWith(hash('sha256', '{"a":1}'), $a);
        $this->assertSame("GET\n/v1/x\n\n1\nn\n" . hash('sha256', ''), AicS2sSignature::canonical('GET', '/api/v1/x', '', '1', 'n', ''));
    }

    public function testHeadersCarryEverythingTheReceiverNeeds(): void
    {
        $h = AicS2sSignature::headers('manage', 'k1', self::SECRET, 'sandbox', 'GET', '/v1/manage/licence-types', '', '', 'uuid-1', 1792310400, 'nonce-0123456789abcdef');
        $this->assertSame(['X-AIC-Client', 'X-AIC-Key-Id', 'X-AIC-Timestamp', 'X-AIC-Nonce', 'X-AIC-Environment', 'X-AIC-Signature', 'X-AIC-Actor', 'X-AIC-Request-Id', 'X-Saas-Origin'], array_keys($h));
        $this->assertSame(AicS2sSignature::sign(self::SECRET, AicS2sSignature::canonical('GET', '/v1/manage/licence-types', '', '1792310400', 'nonce-0123456789abcdef', '')), $h['X-AIC-Signature']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', AicS2sSignature::uuid4());
    }

    public function testConfigKeyIdsSecretsAndScopes(): void
    {
        $set = ['ENGAGE_S2S_ENVIRONMENT' => 'Production', 'ENGAGE_INBOUND_TESTCLIENT_KEY_IDS' => 'a-1, b.2,bad id,c3',
            'ENGAGE_INBOUND_TESTCLIENT_SECRET_A_1' => str_repeat('a', 32), 'ENGAGE_INBOUND_TESTCLIENT_SECRET_B_2' => str_repeat('b', 40),
            'ENGAGE_INBOUND_TESTCLIENT_SCOPE_B_2' => 'issue', 'ENGAGE_INBOUND_TESTCLIENT_SECRET_C3' => 'short'];
        foreach ($set as $k => $v) {
            putenv("{$k}={$v}");
        }
        try {
            $c = AicS2sConfig::forClient('testclient');
            $this->assertTrue($c->isConfigured());
            $this->assertSame('production', $c->environment);
            $this->assertSame(['a-1', 'b.2'], array_keys($c->keys), 'short secret / malformed id dropped');
            $this->assertSame('read', $c->keys['a-1']['scope']);
            $this->assertSame('issue', $c->keys['b.2']['scope']);
            $this->assertStringNotContainsString(str_repeat('a', 32), $c->status());
            putenv('ENGAGE_S2S_ENVIRONMENT=staging');
            $this->assertFalse(AicS2sConfig::forClient('testclient')->isConfigured(), 'unknown environment');
        } finally {
            foreach (array_keys($set) as $k) {
                putenv($k);
            }
        }
        $this->assertFalse(AicS2sConfig::forClient('testclient')->isConfigured());
        $this->assertStringContainsString('dormant', AicS2sConfig::forClient('testclient')->status());
    }
}

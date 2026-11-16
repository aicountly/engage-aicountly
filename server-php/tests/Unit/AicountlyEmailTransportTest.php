<?php

namespace Tests\Unit;

use App\Libraries\Email\AicountlyEmailConfig;
use App\Libraries\Email\AicountlyEmailTransport;
use App\Libraries\Email\EmailDispatcher;
use App\Libraries\Email\EmailProviderResolver;
use App\Services\Seller\SellerMailer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The "AICOUNTLY Email" provider (central delivery API, email-aicountly docs/DELIVERY_API.md) against a stubbed
 * HTTP endpoint: request shape, category selection, idempotency, retry semantics, refusals before any request,
 * selection by ENGAGE_EMAIL_PROVIDER only (default unchanged), and seller mail through it. Nothing leaves the process.
 */
final class AicountlyEmailTransportTest extends CIUnitTestCase
{
    private const KEY = 'svc-key-engage-0123456789abcdef';

    /** @var list<array{url:string, headers:array<string,string>, body:array<string,mixed>, raw:string}> */
    private array $calls = [];
    /** @var list<array{status:int, body:string, headers?:array<string,string>, error?:string}> */
    private array $answers = [];
    /** @var list<int> */
    private array $slept = [];
    /** @var list<array<string,mixed>> */
    private array $recorded = [];
    /** @var list<string> */
    private array $set = [];
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->set as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        EmailProviderResolver::clearMemo();
        parent::tearDown();
    }

    private function env(string $k, ?string $v): void
    {
        $this->set[] = $k;
        if ($v === null) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        } else {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
    }

    private static function accepted(string $id = 'dlv_1', bool $replayed = false): array
    {
        $body = ['data' => ['id' => $id, 'category' => 'transactional', 'status' => 'queued', 'recipients' => []]];

        return ['status' => $replayed ? 200 : 202, 'body' => (string) json_encode($body + ($replayed ? ['replayed' => true] : []))];
    }

    private static function error(int $status, string $code, string $message, array $details = [], array $headers = []): array
    {
        return ['status' => $status, 'body' => (string) json_encode(['error' => ['code' => $code, 'message' => $message, 'details' => $details], 'message' => $message]), 'headers' => $headers];
    }

    private function transport(?callable $ledger = null): AicountlyEmailTransport
    {
        return new AicountlyEmailTransport(
            function (string $url, array $headers, string $body, int $timeout): array {
                $h = [];
                foreach ($headers as $line) {
                    [$k, $v] = explode(':', $line, 2);
                    $h[strtolower(trim($k))] = trim($v);
                }
                $this->calls[] = ['url' => $url, 'headers' => $h, 'body' => (array) json_decode($body, true), 'raw' => $body];

                return array_shift($this->answers) ?? ['status' => 0, 'body' => '', 'error' => 'no stub answer'];
            },
            function (int $s): void {
                $this->slept[] = $s;
            },
            $ledger ?? function (array $r): void {
                $this->recorded[] = $r;
            },
        );
    }

    private static function provider(array $config = []): array
    {
        return ['code' => 'aicountly_email', 'type' => 'aicountly_email', 'config' => $config + ['base_url' => 'https://email.test/v1'], 'secret' => self::KEY];
    }

    private static function msg(array $delivery = [], array $extra = []): array
    {
        return $extra + [
            'to' => 'seller@example.com', 'subject' => 'Your sign-in code', 'html' => '<p>Code <b>482913</b></p>', 'text' => 'Code 482913',
            'from_email' => 'sellers@aicountly.com', 'from_name' => 'Aicountly Sellers', 'reply_to' => 'sellers@aicountly.com',
            'headers' => ['List-Unsubscribe' => '<https://x.test/u>'], 'delivery' => $delivery,
        ];
    }

    // ── request shape ────────────────────────────────────────────────────────

    public function testTransactionalRequestShape(): void
    {
        $this->answers = [self::accepted('dlv_42')];
        $r = $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-7', 'template_ref' => 'engage.seller_login_otp']));

        $this->assertTrue($r['ok']);
        $this->assertSame('dlv_42', $r['id']);
        $this->assertSame('queued', $r['status']);
        $this->assertFalse($r['replayed']);
        $this->assertCount(1, $this->calls);
        $call = $this->calls[0];
        $this->assertSame('https://email.test/v1/service/deliveries', $call['url']);
        $this->assertSame(self::KEY, $call['headers']['x-service-key']);
        $this->assertSame('engage.seller-otp-7', $call['headers']['idempotency-key']);
        $this->assertSame('application/json', $call['headers']['content-type']);
        $this->assertArrayNotHasKey('x-actor-uuid', $call['headers'], 'Engage acts as itself');
        $this->assertArrayNotHasKey('authorization', $call['headers']);
        $this->assertSame([
            'category'     => 'transactional',
            'to'           => ['seller@example.com'],
            'subject'      => 'Your sign-in code',
            'body_text'    => 'Code 482913',
            'body_html'    => '<p>Code <b>482913</b></p>',
            'from_name'    => 'Aicountly Sellers',
            'reply_to'     => 'sellers@aicountly.com',
            'template_ref' => 'engage.seller_login_otp',
        ], $call['body']);
        // No free-form From, no custom headers: the API sets both per category.
        $this->assertStringNotContainsString('from_email', $call['raw']);
        $this->assertStringNotContainsString('List-Unsubscribe', $call['raw']);
        $this->assertSame([['idempotency_key' => 'engage.seller-otp-7', 'delivery_id' => 'dlv_42', 'category' => 'transactional', 'cmp_id' => null,
            'template_ref' => 'engage.seller_login_otp', 'recipients' => ['seller@example.com'], 'status' => 'queued', 'replayed' => false]], $this->recorded);
    }

    public function testDefaultBaseUrlIsProduction(): void
    {
        $this->answers = [self::accepted()];
        $p = self::provider();
        unset($p['config']['base_url']);
        $this->transport()->send($p, self::msg(['idempotency_key' => 'abcdefgh']));
        $this->assertSame('https://email.apis.aicountly.com/v1/service/deliveries', $this->calls[0]['url']);
    }

    public function testMultipleRecipientsAndAttachments(): void
    {
        $this->answers = [self::accepted()];
        $r = $this->transport()->send(self::provider(), self::msg([
            'idempotency_key' => 'invoice-77',
            'attachments' => [
                ['filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'content_base64' => base64_encode('%PDF')],
                ['filename' => '../notes.txt', 'content' => 'hello'],
            ],
        ], ['to' => 'a@example.com, b@example.com']));
        $this->assertTrue($r['ok']);
        $this->assertSame(['a@example.com', 'b@example.com'], $this->calls[0]['body']['to']);
        $this->assertSame([
            ['filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'content_base64' => base64_encode('%PDF')],
            ['filename' => 'notes.txt', 'content_type' => 'application/octet-stream', 'content_base64' => base64_encode('hello')],
        ], $this->calls[0]['body']['attachments']);
    }

    // ── category selection ───────────────────────────────────────────────────

    public function testBusinessCategoryFromCompanyDomain(): void
    {
        $this->answers = [self::accepted()];
        $r = $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'proposal-9', 'cmp_id' => 123, 'from_domain' => 'Acme.example', 'from_local_part' => 'Sales']));
        $this->assertTrue($r['ok']);
        $this->assertSame('business', $r['category']);
        $b = $this->calls[0]['body'];
        $this->assertSame('business', $b['category']);
        $this->assertSame(123, $b['cmp_id']);
        $this->assertSame('acme.example', $b['from_domain']);
        $this->assertSame('sales', $b['from_local_part']);
    }

    public function testCompanyIdAloneStaysTransactional(): void
    {
        $this->answers = [self::accepted()];
        $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'note-123', 'cmp_id' => 55]));
        $this->assertSame('transactional', $this->calls[0]['body']['category']);
        $this->assertSame(55, $this->calls[0]['body']['cmp_id']);
        $this->assertArrayNotHasKey('from_domain', $this->calls[0]['body']);
    }

    public function testExplicitCategoryWins(): void
    {
        $this->answers = [self::accepted()];
        $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'note-124', 'category' => 'transactional', 'cmp_id' => 55, 'from_domain' => 'acme.example']));
        $this->assertSame('transactional', $this->calls[0]['body']['category']);
        $this->assertArrayNotHasKey('from_domain', $this->calls[0]['body'], 'transactional mail never carries a company From');
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function refusedBeforeAnyRequest(): iterable
    {
        yield 'marketing is Reach\'s' => [['category' => 'marketing'], 'category marketing'];
        yield 'unknown category'      => [['category' => 'promo'], 'category promo'];
        yield 'business without company' => [['category' => 'business', 'from_domain' => 'acme.example'], 'needs cmp_id'];
        yield 'business without domain'  => [['category' => 'business', 'cmp_id' => 5], 'needs from_domain'];
        yield 'business bad domain'      => [['category' => 'business', 'cmp_id' => 5, 'from_domain' => 'not a domain'], 'needs from_domain'];
        yield 'business bad local part'  => [['cmp_id' => 5, 'from_domain' => 'acme.example', 'from_local_part' => 'bad local!'], 'from_local_part'];
        yield 'executable .exe'          => [['attachments' => [['filename' => 'setup.EXE', 'content' => 'MZ']]], '.exe'];
        yield 'executable .js'           => [['attachments' => [['filename' => 'a.pdf'], ['filename' => 'run.js', 'content' => 'x']]], '.js'];
        yield 'executable .ps1'          => [['attachments' => [['filename' => 'x.ps1', 'content' => 'x']]], '.ps1'];
        yield 'executable .iso'          => [['attachments' => [['filename' => 'disk.iso', 'content' => 'x']]], '.iso'];
        yield 'too many attachments'     => [['attachments' => array_fill(0, 11, ['filename' => 'a.txt', 'content' => 'x'])], 'attachments'];
    }

    /** @dataProvider refusedBeforeAnyRequest */
    public function testRefusedLocallyWithoutRequest(array $delivery, string $expect): void
    {
        $r = $this->transport()->send(self::provider(), self::msg($delivery + ['idempotency_key' => 'k-12345678']));
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['retryable']);
        $this->assertStringContainsString($expect, (string) $r['error']);
        $this->assertSame([], $this->calls);
    }

    public function testTooManyRecipientsRefused(): void
    {
        $to = implode(',', array_map(static fn (int $i): string => "p{$i}@example.com", range(1, 51)));
        $r = $this->transport()->send(self::provider(), self::msg([], ['to' => $to]));
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->calls);
    }

    public function testMissingServiceKeySendsNothing(): void
    {
        $p = self::provider();
        $p['secret'] = '';
        $r = $this->transport()->send($p, self::msg());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('AICOUNTLY_EMAIL_SERVICE_KEY', (string) $r['error']);
        $this->assertSame([], $this->calls);
    }

    // ── idempotency ──────────────────────────────────────────────────────────

    public function testDerivedKeyIsStablePerMessage(): void
    {
        $this->answers = [self::accepted('a'), self::accepted('a', true), self::accepted('b')];
        $t = $this->transport();
        $first  = $t->send(self::provider(), self::msg());
        $again  = $t->send(self::provider(), self::msg());
        $other  = $t->send(self::provider(), self::msg([], ['subject' => 'Another subject']));

        $this->assertSame($first['idempotency_key'], $again['idempotency_key'], 'the same message always carries the same key');
        $this->assertNotSame($first['idempotency_key'], $other['idempotency_key']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:\-]{8,120}$/', $first['idempotency_key']);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['replayed'], '200 replayed: accepted earlier, not sent twice');
        $this->assertSame('a', $again['id']);
    }

    public function testCallerKeyIsUsedOrHashedIntoTheApiAlphabet(): void
    {
        $this->assertSame('engage.seller-activation-12', AicountlyEmailTransport::idempotencyKey([], ['idempotency_key' => 'seller-activation-12']));
        $odd = AicountlyEmailTransport::idempotencyKey([], ['idempotency_key' => 'ticket #12 / reply ✓']);
        $this->assertMatchesRegularExpression('/^engage\.k\.[0-9a-f]{64}$/', $odd);
        $this->assertSame($odd, AicountlyEmailTransport::idempotencyKey([], ['idempotency_key' => 'ticket #12 / reply ✓']));
        $long = AicountlyEmailTransport::idempotencyKey([], ['idempotency_key' => str_repeat('a', 200)]);
        $this->assertLessThanOrEqual(120, strlen($long));
        // A caller key is kept even for a resend: the caller decides when a message is new.
        $this->assertSame('engage.seller-activation-12', AicountlyEmailTransport::idempotencyKey([], ['idempotency_key' => 'seller-activation-12', 'resend' => true]));
    }

    public function testResendWithoutCallerKeyIsANewMessage(): void
    {
        $payload = ['category' => 'transactional', 'to' => ['a@example.com'], 'subject' => 's', 'body_text' => 't'];
        $a = AicountlyEmailTransport::idempotencyKey($payload, ['resend' => true]);
        $b = AicountlyEmailTransport::idempotencyKey($payload, ['resend' => true]);
        $this->assertNotSame($a, $b);
        $this->assertSame(AicountlyEmailTransport::idempotencyKey($payload, []), AicountlyEmailTransport::idempotencyKey($payload, []));
    }

    // ── retry semantics ──────────────────────────────────────────────────────

    public function testRetries503WithTheSameKeyAndBody(): void
    {
        $this->answers = [self::error(503, 'mail_server_unavailable', 'retry with the same Idempotency-Key'), self::accepted('dlv_9')];
        $r = $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-1']));
        $this->assertTrue($r['ok']);
        $this->assertSame('dlv_9', $r['id']);
        $this->assertSame(2, $r['attempts']);
        $this->assertCount(2, $this->calls);
        $this->assertSame($this->calls[0]['headers']['idempotency-key'], $this->calls[1]['headers']['idempotency-key']);
        $this->assertSame($this->calls[0]['raw'], $this->calls[1]['raw']);
        $this->assertSame([1], $this->slept);
        $this->assertCount(1, $this->recorded);
    }

    public function testRetries429AfterRetryAfterWithTheSameKey(): void
    {
        $this->answers = [self::error(429, 'rate_limited', 'slow down', [], ['retry-after' => '2']), self::accepted()];
        $r = $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-2']));
        $this->assertTrue($r['ok']);
        $this->assertSame([2], $this->slept);
        $this->assertSame('engage.seller-otp-2', $this->calls[1]['headers']['idempotency-key']);
    }

    public function testLongRetryAfterStopsWithoutWaiting(): void
    {
        $this->answers = [self::error(429, 'rate_limited', 'More than 1200 transactional messages a minute', [], ['retry-after' => '60']), self::accepted()];
        $r = $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-3']));
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['retryable'], 'the caller may retry later with the same key');
        $this->assertSame(429, $r['http_status']);
        $this->assertCount(1, $this->calls);
        $this->assertSame([], $this->slept);
        $this->assertStringContainsString('same Idempotency-Key', (string) $r['error']);
    }

    public function testNoAnswerIsRetriedUpToMaxAttempts(): void
    {
        $this->answers = [['status' => 0, 'body' => '', 'error' => 'timeout'], ['status' => 503, 'body' => ''], ['status' => 502, 'body' => ''], self::accepted()];
        $r = $this->transport()->send(self::provider(['max_attempts' => 3]), self::msg(['idempotency_key' => 'seller-otp-4']));
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['retryable']);
        $this->assertSame(3, $r['attempts']);
        $this->assertCount(3, $this->calls);
        $this->assertSame([1, 2], $this->slept);
        $this->assertCount(1, array_unique(array_map(static fn (array $c): string => $c['headers']['idempotency-key'], $this->calls)));
        $this->assertSame([], $this->recorded);
    }

    public function testThrowingHttpIsTreatedAsNoAnswer(): void
    {
        $calls = 0;
        $t = new AicountlyEmailTransport(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('connection reset');
            }

            return self::accepted('dlv_x');
        }, static function (int $s): void {
        }, static function (array $r): void {
        });
        $r = $t->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-5']));
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $calls);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function notRetried(): iterable
    {
        yield '422 invalid input' => [422, 'validation_failed', 'field from_domain'];
        yield '403 not permitted' => [403, 'category_not_permitted', 'DELIVERY_PRODUCTS'];
        yield '409 key reuse'     => [409, 'idempotency_conflict', 'reused for a different message'];
        yield '400 bad key'       => [400, 'idempotency_key_required', 'idempotency_key_required'];
        yield '401 bad key'       => [401, 'unauthorized', 'AICOUNTLY_EMAIL_SERVICE_KEY'];
    }

    /** @dataProvider notRetried */
    public function testNonRetryableAnswersAreNotRetried(int $status, string $code, string $expect): void
    {
        $this->answers = [self::error($status, $code, 'refused', ['field' => 'from_domain']), self::accepted()];
        $r = $this->transport()->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-6']));
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['retryable']);
        $this->assertSame($status, $r['http_status']);
        $this->assertCount(1, $this->calls);
        $this->assertSame([], $this->slept);
        $this->assertStringContainsString($expect, (string) $r['error']);
        $this->assertSame([], $this->recorded);
    }

    public function testLedgerFailureDoesNotFailAnAcceptedMessage(): void
    {
        $this->answers = [self::accepted('dlv_ok')];
        $r = $this->transport(static function (): void {
            throw new \RuntimeException('db down');
        })->send(self::provider(), self::msg(['idempotency_key' => 'seller-otp-8']));
        $this->assertTrue($r['ok']);
        $this->assertSame('dlv_ok', $r['id']);
    }

    // ── selection by configuration ───────────────────────────────────────────

    private function resolver(array $env, array &$consoleCalls): EmailProviderResolver
    {
        $dir = sys_get_temp_dir() . '/engage-aic-email-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->dirs[] = $dir;

        return new EmailProviderResolver(
            function (string $url) use (&$consoleCalls): array {
                $consoleCalls[] = $url;

                return ['status' => 200, 'body' => (string) json_encode(['data' => ['ttl_seconds' => 60, 'providers' => [
                    ['code' => 'itwalk_api', 'type' => 'http_api', 'priority' => 10, 'config' => [], 'secret' => 'itwalk-key-123456'],
                ]]]), 'error' => ''];
            },
            $dir,
            static fn (): int => 1_000_000,
            static fn (string $k): string => (string) ($env[$k] ?? ''),
        );
    }

    public function testDefaultConfigurationKeepsConsoleProviders(): void
    {
        $calls = [];
        foreach (['', 'console', 'something-else'] as $value) {
            EmailProviderResolver::clearMemo();
            $r = $this->resolver(['ENGAGE_EMAIL_PROVIDER' => $value, 'CONSOLE_API_URL' => 'https://console.test/api', 'CONSOLE_API_TOKEN' => 'tok',
                'AICOUNTLY_EMAIL_SERVICE_KEY' => self::KEY], $calls)->resolve();
            $this->assertSame('console', $r['source'], "ENGAGE_EMAIL_PROVIDER='{$value}'");
            $this->assertSame(['itwalk_api'], array_column($r['providers'], 'code'));
        }
        $this->assertNotEmpty($calls);
    }

    public function testSelectingAicountlyEmailReplacesConsoleProviders(): void
    {
        $calls = [];
        $r = $this->resolver(['ENGAGE_EMAIL_PROVIDER' => 'aicountly_email', 'CONSOLE_API_URL' => 'https://console.test/api', 'CONSOLE_API_TOKEN' => 'tok',
            'AICOUNTLY_EMAIL_SERVICE_KEY' => self::KEY, 'AICOUNTLY_EMAIL_API_BASE_URL' => 'https://email.gh.aicountly.com/api/v1/'], $calls)->resolve();
        $this->assertSame('aicountly_email', $r['source']);
        $this->assertSame([], $calls, 'Console is not asked');
        $this->assertCount(1, $r['providers']);
        $p = $r['providers'][0];
        $this->assertSame(['aicountly_email', 'aicountly_email', self::KEY], [$p['code'], $p['type'], $p['secret']]);
        $this->assertSame('https://email.gh.aicountly.com/api/v1', $p['config']['base_url']);
        $this->assertSame('email via aicountly_email', EmailProviderResolver::describe($r));
    }

    public function testSelectedWithoutKeyResolvesToNoProvider(): void
    {
        $calls = [];
        $r = $this->resolver(['ENGAGE_EMAIL_PROVIDER' => 'aicountly_email', 'CONSOLE_API_URL' => 'https://console.test/api', 'CONSOLE_API_TOKEN' => 'tok'], $calls)->resolve();
        $this->assertSame('none', $r['source']);
        $this->assertSame([], $r['providers']);
        $this->assertStringContainsString('AICOUNTLY_EMAIL_SERVICE_KEY', (string) $r['note']);
        $this->assertSame([], $calls, 'no silent fallback to Console');
    }

    public function testConfigBounds(): void
    {
        $env = static fn (array $v): AicountlyEmailConfig => new AicountlyEmailConfig(static fn (string $k): string => (string) ($v[$k] ?? ''));
        $c = $env([]);
        $this->assertFalse($c->selected());
        $this->assertSame([3, 5, 10], [$c->maxAttempts(), $c->maxRetryWait(), $c->timeout()]);
        $this->assertSame('https://email.apis.aicountly.com/v1', $c->baseUrl());
        $c = $env(['ENGAGE_EMAIL_PROVIDER' => 'AICOUNTLY_EMAIL', 'AICOUNTLY_EMAIL_MAX_ATTEMPTS' => '99', 'AICOUNTLY_EMAIL_MAX_RETRY_WAIT' => '0',
            'AICOUNTLY_EMAIL_API_BASE_URL' => 'http://plain.test/v1', 'AICOUNTLY_EMAIL_SERVICE_KEY' => 'k']);
        $this->assertTrue($c->selected());
        $this->assertSame([5, 0], [$c->maxAttempts(), $c->maxRetryWait()]);
        $this->assertStringContainsString('https', (string) $c->problem());
    }

    public function testDispatcherSendsThroughTheProviderAndRedactsTheKey(): void
    {
        $calls = [];
        $resolver = $this->resolver(['ENGAGE_EMAIL_PROVIDER' => 'aicountly_email', 'AICOUNTLY_EMAIL_SERVICE_KEY' => self::KEY], $calls);
        $this->answers = [self::error(422, 'validation_failed', 'bad key ' . self::KEY, ['field' => 'to'])];
        $transport = $this->transport();
        $d = new EmailDispatcher($resolver, static fn (array $p): object => $transport);

        $r = $d->send('a@example.com', 'Hi', '<p>Hi</p>', null, ['idempotency_key' => 'note-77', 'cmp_id' => 9, 'from_domain' => 'acme.example', 'from_local_part' => 'billing', 'template_ref' => 'engage.x']);
        $this->assertFalse($r['ok']);
        $this->assertSame('aicountly_email', $r['source']);
        $this->assertStringNotContainsString(self::KEY, (string) $r['error']);
        $this->assertStringContainsString('[redacted]', (string) $r['error']);
        $body = $this->calls[0]['body'];
        $this->assertSame(['business', 9, 'acme.example', 'billing', 'engage.x'], [$body['category'], $body['cmp_id'], $body['from_domain'], $body['from_local_part'], $body['template_ref']]);
        $this->assertSame('engage.note-77', $this->calls[0]['headers']['idempotency-key']);

        $this->answers = [self::accepted('dlv_77')];
        $r = $d->send('a@example.com', 'Hi', '<p>Hi</p>', null, ['idempotency_key' => 'note-78']);
        $this->assertTrue($r['ok']);
        $this->assertSame(['aicountly_email', 'aicountly_email', 'dlv_77', 'aicountly_email'], [$r['provider'], $r['provider_type'], $r['id'], $r['mode']]);
    }

    public function testDefaultFactoryBuildsTheAicountlyTransport(): void
    {
        $calls = [];
        $d = new EmailDispatcher($this->resolver([], $calls));
        $factory = (new \ReflectionProperty(EmailDispatcher::class, 'transportFactory'))->getValue($d);
        $this->assertInstanceOf(AicountlyEmailTransport::class, $factory(['type' => 'aicountly_email']));
        $this->assertInstanceOf(\App\Libraries\Email\SmtpProviderTransport::class, $factory(['type' => 'smtp']));
        $this->assertInstanceOf(\App\Libraries\Email\HttpApiProviderTransport::class, $factory(['type' => 'http_api']));
    }

    // ── seller mail through it ───────────────────────────────────────────────

    public function testSellerMailUsesTheCallersKeyWithoutConsole(): void
    {
        $this->env('ENGAGE_SELLER_MAILER', null);
        $this->env('CONSOLE_API_URL', '');
        $this->env('CONSOLE_API_BASE_URL', '');
        $this->env('CONSOLE_API_TOKEN', '');
        $this->env('ENGAGE_SMTP_HOST', null);
        $this->env('ENGAGE_MAIL_FROM', null);
        $this->env('ENGAGE_EMAIL_PROVIDER', 'aicountly_email');
        $this->env('AICOUNTLY_EMAIL_SERVICE_KEY', self::KEY);

        $calls = [];
        $resolver = $this->resolver(['ENGAGE_EMAIL_PROVIDER' => 'aicountly_email', 'AICOUNTLY_EMAIL_SERVICE_KEY' => self::KEY], $calls);
        $transport = $this->transport();
        $mailer = new SellerMailer(null, new EmailDispatcher($resolver, static fn (array $p): object => $transport));
        $this->assertSame('console_providers', $mailer->channel());
        $this->assertStringContainsString('AICOUNTLY Email delivery API: email via aicountly_email', $mailer->status());

        $this->answers = [self::accepted('dlv_otp')];
        $r = $mailer->send('seller_login_otp', 'seller@example.com', 'Asha', ['name' => 'Asha', 'code' => '482913', 'expires_minutes' => '10'], 'seller-otp-31');
        $this->assertSame(['sent' => true, 'channel' => 'console_providers', 'reason' => null, 'provider' => 'aicountly_email'], $r);
        $this->assertSame('engage.seller-otp-31', $this->calls[0]['headers']['idempotency-key']);
        $this->assertSame('transactional', $this->calls[0]['body']['category']);
        $this->assertSame('engage.seller_login_otp', $this->calls[0]['body']['template_ref']);
        $this->assertSame('Aicountly Sellers', $this->calls[0]['body']['from_name']);
        $this->assertStringContainsString('482913', $this->calls[0]['body']['body_text']);
        $this->assertSame([], $calls);
    }

    public function testSellerMailUnchangedByDefault(): void
    {
        $this->env('ENGAGE_SELLER_MAILER', null);
        $this->env('ENGAGE_EMAIL_PROVIDER', null);
        $this->env('CONSOLE_API_URL', '');
        $this->env('CONSOLE_API_BASE_URL', '');
        $this->env('CONSOLE_API_TOKEN', '');
        $this->env('ENGAGE_SMTP_HOST', null);
        $this->env('ENGAGE_MAIL_FROM', null);
        $this->env('AICOUNTLY_EMAIL_SERVICE_KEY', self::KEY);
        $this->assertNull((new SellerMailer())->channel(), 'a service key alone switches nothing on');
    }
}

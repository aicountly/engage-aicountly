<?php

namespace Tests\Unit;

use App\Libraries\Email\EmailDispatcher;
use App\Libraries\Email\EmailProviderResolver;
use App\Services\Seller\SellerMailer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Seller email through Console's email providers (app_code engage), against a stubbed Console endpoint and stubbed
 * transports: primary, failover, resend order, 401 dropping the cache, stale cache on outage, and the ENGAGE_SMTP_*
 * last resort when Console gives no provider. Nothing leaves the process.
 */
final class SellerMailerProvidersTest extends CIUnitTestCase
{
    private const PROVIDERS = [
        ['code' => 'itwalk_api', 'type' => 'http_api', 'priority' => 10, 'config' => ['api_url' => 'https://e.api.itwalk.in/email/1/send', 'api_format' => 'auto'], 'secret' => 'itwalk-key-123456'],
        ['code' => 'aicountly_smtp', 'type' => 'smtp', 'priority' => 20, 'config' => ['host' => 'mail.aicountly.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'engage@aicountly.com', 'from_email' => 'sellers@aicountly.com'], 'secret' => 'smtp-pass-XYZ'],
    ];

    private string $dir;
    private int $now = 2_000_000;
    /** @var list<string> */
    private array $set = [];
    /** @var list<array{url:string, token:string}> */
    private array $consoleCalls = [];
    /** @var array{status:int, body:string, error:string} */
    private array $console = ['status' => 0, 'body' => '', 'error' => 'down'];
    /** @var array<string, bool> provider code => accepts */
    private array $accepts = [];
    /** @var list<array{provider:string, msg:array}> */
    private array $attempts = [];
    /** @var list<array{email:string, subject:string, text:string}> */
    private array $smtpSent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/engage-email-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0700, true);
        EmailProviderResolver::clearMemo();
        $this->env('ENGAGE_SELLER_MAILER', null);
        $this->env('CONSOLE_API_URL', 'https://console.test/api');
        $this->env('CONSOLE_API_TOKEN', 'engage-caller-key');
        $this->env('ENGAGE_SMTP_HOST', null);
        $this->env('ENGAGE_MAIL_FROM', null);
        $this->env('ENGAGE_MAIL_FROM_NAME', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->set as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
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

    private function answer(array $providers, int $ttl = 300): void
    {
        $this->console = ['status' => 200, 'body' => (string) json_encode(['success' => true, 'data' => ['app_code' => 'engage', 'ttl_seconds' => $ttl, 'providers' => $providers]]), 'error' => ''];
    }

    private function mailer(): SellerMailer
    {
        $resolver = new EmailProviderResolver(
            function (string $url, string $token): array {
                $this->consoleCalls[] = ['url' => $url, 'token' => $token];

                return $this->console;
            },
            $this->dir,
            fn (): int => $this->now,
            static fn (string $k): string => trim((string) (getenv($k) ?: '')),
        );
        $test = $this;
        $dispatcher = new EmailDispatcher($resolver, static fn (array $provider): object => new class ($test) {
            public function __construct(private SellerMailerProvidersTest $t)
            {
            }

            public function send(array $provider, array $msg): array
            {
                return $this->t->transportSend($provider, $msg);
            }
        });

        return new SellerMailer(null, $dispatcher, function (string $email, string $name, string $subject, string $text): bool {
            $this->smtpSent[] = ['email' => $email, 'subject' => $subject, 'text' => $text];

            return true;
        });
    }

    /** @internal called by the stub transport */
    public function transportSend(array $provider, array $msg): array
    {
        $this->attempts[] = ['provider' => $provider['code'], 'msg' => $msg];
        $ok = $this->accepts[$provider['code']] ?? false;

        return $ok ? ['ok' => true, 'id' => 'msg-' . count($this->attempts), 'error' => null]
            : ['ok' => false, 'error' => 'rejected (key ' . $provider['secret'] . ')'];
    }

    private function otp(SellerMailer $m, array $opts = []): array
    {
        return $m->send('seller_login_otp', 'ravi@example.com', 'Ravi', ['name' => 'Ravi', 'code' => '424242', 'expires_minutes' => '10'], 'seller-otp-1', $opts);
    }

    public function testDefaultModeSendsThroughTheConsolePrimary(): void
    {
        $this->answer(self::PROVIDERS);
        $this->accepts = ['itwalk_api' => true, 'aicountly_smtp' => true];
        $m = $this->mailer();

        $this->assertSame('console_providers', $m->channel(), 'unset ENGAGE_SELLER_MAILER = Console providers');
        $r = $this->otp($m);

        $this->assertSame(['sent' => true, 'channel' => 'console_providers', 'reason' => null, 'provider' => 'itwalk_api'], $r);
        $this->assertSame([['url' => 'https://console.test/api/portal/email/providers?app_code=engage', 'token' => 'engage-caller-key']], $this->consoleCalls);
        $this->assertCount(1, $this->attempts, 'one copy only');
        $msg = $this->attempts[0]['msg'];
        $this->assertSame('ravi@example.com', $msg['to']);
        $this->assertSame('Your Aicountly seller sign-in code', $msg['subject']);
        $this->assertSame('Aicountly Sellers', $msg['from_name']);
        $this->assertStringContainsString('424242', $msg['text']);
        $this->assertSame([], $this->smtpSent);
        $this->assertStringContainsString('email via itwalk_api, fallback aicountly_smtp', $m->status());
        $this->assertStringNotContainsString('itwalk-key', $m->status());

        // Cached: a second send in the same process does not ask Console again.
        $this->otp($m);
        $this->assertCount(1, $this->consoleCalls);
    }

    public function testPrimaryFailsThenFallbackSends(): void
    {
        $this->answer(self::PROVIDERS);
        $this->accepts = ['itwalk_api' => false, 'aicountly_smtp' => true];
        $this->env('ENGAGE_MAIL_FROM_NAME', 'Aicountly Seller Desk');

        $r = $this->otp($this->mailer());

        $this->assertTrue($r['sent']);
        $this->assertSame('aicountly_smtp', $r['provider']);
        $this->assertSame(['itwalk_api', 'aicountly_smtp'], array_column($this->attempts, 'provider'));
        $this->assertSame('sellers@aicountly.com', $this->attempts[1]['msg']['from_email'], "the provider's own from address");
        $this->assertSame('Aicountly Seller Desk', $this->attempts[1]['msg']['from_name']);
    }

    public function testResendTriesTheFallbackFirst(): void
    {
        $this->answer(self::PROVIDERS);
        $this->accepts = ['itwalk_api' => true, 'aicountly_smtp' => true];

        $r = $this->otp($this->mailer(), ['resend' => true]);

        $this->assertSame('aicountly_smtp', $r['provider']);
        $this->assertSame(['aicountly_smtp'], array_column($this->attempts, 'provider'));
    }

    public function testNoProviderAcceptsMeansNotSentAndNoSmtpCopy(): void
    {
        $this->answer(self::PROVIDERS);
        $this->env('ENGAGE_SMTP_HOST', 'smtp.local');
        $this->env('ENGAGE_MAIL_FROM', 'sellers@aicountly.com');

        $r = $this->otp($this->mailer());

        $this->assertSame(['sent' => false, 'channel' => 'console_providers', 'reason' => 'providers_rejected', 'provider' => null], $r);
        $this->assertCount(2, $this->attempts);
        $this->assertSame([], $this->smtpSent, 'Console answered with providers: its list is authoritative');
    }

    public function testEmptyProviderListFallsBackToEngageSmtp(): void
    {
        $this->answer([]);
        $m = $this->mailer();
        $this->assertSame(['sent' => false, 'channel' => 'console_providers', 'reason' => 'no_provider', 'provider' => null], $this->otp($m), 'no SMTP configured either');

        $this->env('ENGAGE_SMTP_HOST', 'smtp.local');
        $this->env('ENGAGE_MAIL_FROM', 'sellers@aicountly.com');
        $r = $this->otp($m);

        $this->assertSame(['sent' => true, 'channel' => 'smtp', 'reason' => null, 'provider' => null], $r);
        $this->assertSame([], $this->attempts);
        $this->assertSame('Your Aicountly seller sign-in code', $this->smtpSent[0]['subject']);
    }

    public function testRefusedKeyDropsTheCacheAndUsesSmtp(): void
    {
        $this->answer(self::PROVIDERS, 60);
        $this->accepts = ['itwalk_api' => true];
        $this->assertTrue($this->otp($this->mailer())['sent']);
        $this->assertNotSame([], glob($this->dir . '/*.cache') ?: [], 'encrypted cache written');
        $this->assertStringNotContainsString('itwalk-key', (string) file_get_contents((glob($this->dir . '/*.cache') ?: [''])[0]), 'cache is encrypted');

        // TTL passes; Console now refuses the key.
        $this->now += 61;
        EmailProviderResolver::clearMemo();
        $this->console = ['status' => 401, 'body' => '{"error":"revoked"}', 'error' => ''];
        $this->env('ENGAGE_SMTP_HOST', 'smtp.local');
        $this->env('ENGAGE_MAIL_FROM', 'sellers@aicountly.com');
        $this->attempts = [];

        $r = $this->otp($this->mailer());

        $this->assertSame('smtp', $r['channel']);
        $this->assertTrue($r['sent']);
        $this->assertSame([], $this->attempts, "a revoked key must not keep using Console's providers");
        $this->assertSame([], glob($this->dir . '/*.cache') ?: [], 'cache dropped');
    }

    public function testConsoleOutageUsesTheStaleCacheForFifteenMinutes(): void
    {
        $this->answer(self::PROVIDERS, 60);
        $this->accepts = ['itwalk_api' => true];
        $this->otp($this->mailer());

        $this->now += 60 + 600;
        EmailProviderResolver::clearMemo();
        $this->console = ['status' => 0, 'body' => '', 'error' => 'connection refused'];
        $this->assertSame('itwalk_api', $this->otp($this->mailer())['provider'], 'stale list within 15 minutes');

        $this->now += 400; // past TTL + 15 min, and past the 60 s back-off
        EmailProviderResolver::clearMemo();
        $this->assertSame('no_provider', $this->otp($this->mailer())['reason']);
    }

    public function testSmtpModeIgnoresConsole(): void
    {
        $this->env('ENGAGE_SELLER_MAILER', 'smtp');
        $this->env('ENGAGE_SMTP_HOST', 'smtp.local');
        $this->env('ENGAGE_MAIL_FROM', 'sellers@aicountly.com');
        $this->answer(self::PROVIDERS);

        $r = $this->otp($this->mailer());

        $this->assertSame('smtp', $r['channel']);
        $this->assertSame([], $this->consoleCalls);
    }
}

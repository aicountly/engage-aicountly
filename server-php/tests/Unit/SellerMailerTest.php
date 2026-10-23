<?php

namespace Tests\Unit;

use App\Services\Seller\SellerMailer;
use CodeIgniter\Test\CIUnitTestCase;

/** The deprecated Console notify mode (explicit opt-in) reports "sent" only when Console said sent:true. */
final class SellerMailerTest extends CIUnitTestCase
{
    private array $set = [];

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

    protected function tearDown(): void
    {
        foreach ($this->set as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    public function testUnconfiguredSendsNothing(): void
    {
        $this->env('ENGAGE_SELLER_MAILER', null);
        $called = false;
        $m = new SellerMailer(function () use (&$called) { $called = true; return ['status' => 200, 'body' => '']; });
        $this->assertFalse($m->isConfigured());
        $this->assertSame(['sent' => false, 'channel' => null, 'reason' => 'not_configured'], $m->send('seller_activation', 'a@b.co', 'A', [], 'k-12345678'));
        $this->assertFalse($called);
        $this->env('ENGAGE_SELLER_MAILER', 'console');
        $this->assertFalse($m->isConfigured(), 'Console providers need CONSOLE_API_URL + CONSOLE_API_TOKEN (or ENGAGE_SMTP_*)');
        $this->env('ENGAGE_SELLER_MAILER', 'off');
        $this->env('CONSOLE_API_URL', 'https://console.test/api');
        $this->env('CONSOLE_API_TOKEN', 'console-token');
        $this->assertFalse($m->isConfigured(), 'off sends nothing');
    }

    public function testDeprecatedConsoleNotifyChannelIsOptInOnly(): void
    {
        $this->env('ENGAGE_SELLER_MAILER', 'console_notify');
        $this->env('CONSOLE_API_URL', 'https://console.test/api');
        $this->env('CONSOLE_API_TOKEN', 'console-token');
        $seen = null;
        $answer = ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => ['sent' => true, 'channel' => 'email']])];
        $m = new SellerMailer(function (string $url, array $h, string $body) use (&$seen, &$answer) {
            $seen = ['url' => $url, 'headers' => $h, 'body' => json_decode($body, true)];

            return $answer;
        });
        $r = $m->send('seller_activation', 'ravi@example.com', 'Ravi', ['activation_url' => 'https://x'], 'seller-activation-7');
        $this->assertTrue($r['sent']);
        $this->assertSame('https://console.test/api/portal/notifications/send', $seen['url']);
        $this->assertContains('Authorization: Bearer console-token', $seen['headers']);
        $this->assertSame('engage', $seen['body']['product']);
        $this->assertSame('seller_activation', $seen['body']['trigger_key']);
        $this->assertSame(['email' => 'ravi@example.com', 'name' => 'Ravi'], $seen['body']['recipient']);
        $this->assertSame('seller-activation-7', $seen['body']['options']['idempotency_key']);

        $answer = ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => ['sent' => false, 'skipped_reason' => 'template_not_registered']])];
        $r = $m->send('seller_activation', 'ravi@example.com', 'Ravi', [], 'seller-activation-8');
        $this->assertFalse($r['sent']);
        $this->assertSame('template_not_registered', $r['reason']);

        $answer = ['status' => 403, 'body' => json_encode(['ok' => false, 'error' => 'no'])];
        $this->assertFalse($m->send('seller_activation', 'ravi@example.com', 'Ravi', [], 'seller-activation-9')['sent']);
    }
}

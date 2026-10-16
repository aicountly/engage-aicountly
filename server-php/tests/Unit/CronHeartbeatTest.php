<?php

namespace Tests\Unit;

use App\Libraries\CronHeartbeat;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CronHeartbeat: `php spark engage:console-audit-deliver` reports to Console's Cron Job Monitor as engage.console_audit_deliver (console-react-app
 * docs/CRON_MONITORS.md, migration 068). Inert without a key, the body Console's POST portal/cron-heartbeat takes,
 * and never a failure of the job whatever Console answers.
 *
 * @internal
 */
final class CronHeartbeatTest extends CIUnitTestCase
{
    private const KEYS = ['CONSOLE_CRON_MONITOR_KEY', 'CONSOLE_SERVICE_KEY', 'CONSOLE_API_BASE', 'CONSOLE_API_URL'];

    /** @var array<string, string|false> */
    private array $saved = [];

    /** @var list<array{url: string, headers: list<string>, body: mixed}> */
    private array $sent = [];

    private int $answer = 200;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $k) {
            $this->saved[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k]);
        }
        $this->sent = [];
        $this->answer = 200;
        CronHeartbeat::setTransportForTesting(function (string $url, array $headers, string $json): int {
            $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($json, true)];
            if ($this->answer === 0) {
                throw new \RuntimeException('connection refused');
            }

            return $this->answer;
        });
    }

    protected function tearDown(): void
    {
        CronHeartbeat::setTransportForTesting(null);
        foreach ($this->saved as $k => $v) {
            putenv($v === false ? $k : $k . '=' . $v);
        }
        parent::tearDown();
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $this->assertFalse(CronHeartbeat::send('engage.console_audit_deliver', 'ok'));
        $this->assertSame([], $this->sent);
    }

    public function testAReportCarriesTheMonitorKeyAndCounts(): void
    {
        putenv('CONSOLE_CRON_MONITOR_KEY=mon-key');
        putenv('CONSOLE_API_BASE=https://console.test/api/');

        $this->assertTrue(CronHeartbeat::send('engage.console_audit_deliver', 'ok', '', ['delivered' => 2]));
        $this->assertSame('https://console.test/api/portal/cron-heartbeat', $this->sent[0]['url']);
        $this->assertContains('X-Console-Cron-Key: mon-key', $this->sent[0]['headers']);
        $body = $this->sent[0]['body'];
        $this->assertSame(['engage.console_audit_deliver', 'ok', ['delivered' => 2]], [$body['monitor'], $body['outcome'], $body['counts']]);
        $this->assertStringStartsWith('engage.console_audit_deliver-', $body['run_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['finished_at']);
    }

    public function testFallsBackToTheServiceKeyAndConsoleApiUrl(): void
    {
        putenv('CONSOLE_SERVICE_KEY=svc-key');
        putenv('CONSOLE_API_URL=https://console.example/api');

        CronHeartbeat::send('engage.console_audit_deliver', 'ok');
        $this->assertContains('X-Console-Cron-Key: svc-key', $this->sent[0]['headers']);
        $this->assertSame('https://console.example/api/portal/cron-heartbeat', $this->sent[0]['url']);
    }

    public function testARefusalOrATransportFailureNeverThrows(): void
    {
        putenv('CONSOLE_CRON_MONITOR_KEY=mon-key');
        $this->answer = 404;
        $this->assertFalse(CronHeartbeat::send('engage.console_audit_deliver', 'ok'));
        $this->answer = 0;
        $this->assertFalse(CronHeartbeat::send('engage.console_audit_deliver', 'ok'));
    }

    public function testTheCommandReportsUnderItsRegisteredCode(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Commands/EngageConsoleAuditDeliver.php');
        $this->assertStringContainsString("CronHeartbeat::send('engage.console_audit_deliver'", $src);
    }
}

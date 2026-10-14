<?php

namespace Tests\Unit;

use App\Services\ConsoleAuditOutbox;
use App\Services\ConsoleClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Engage → Console without a database: the central-audit event shape and request, and that the
 * Console calls Engage still makes are ones Console serves.
 *
 * @internal
 */
final class ConsoleAuditOutboxTest extends CIUnitTestCase
{
    public function testMapsAnAuditRowToConsoleEvent(): void
    {
        $e = ConsoleAuditOutbox::toEvent('approval.approved', [
            'actor_id' => 3, 'actor_email' => 'ravi@aicountly.com', 'actor_role' => 'super_admin',
            'subject_kind' => 'approval_request', 'subject_id' => '17', 'ip_address' => '10.0.0.3', 'user_agent' => 'UA',
            'metadata' => ['kind' => 'discount'], 'created_at' => '2026-10-14 10:00:00',
        ], 'engage:audit_logs:5');

        $this->assertSame(['approval.approved', 'engage', 'engage:audit_logs:5'], [$e['action'], $e['app_code'], $e['external_id']]);
        $this->assertSame(['approval_request', '17', 'ravi@aicountly.com', '10.0.0.3', 'UA'],
            [$e['entity_type'], $e['entity_id'], $e['actor_email'], $e['ip'], $e['user_agent']]);
        $this->assertSame(['source' => 'engage.aicountly.org', 'actor_id' => 3, 'actor_role' => 'super_admin', 'metadata' => ['kind' => 'discount']], $e['metadata']);
        $this->assertNotFalse(strtotime($e['occurred_at']));
    }

    public function testPostsToConsolePortalAuditWithEngagesOwnKey(): void
    {
        $calls = [];
        $outbox = new ConsoleAuditOutbox(static function (string $url, array $headers, string $body) use (&$calls): array {
            $calls[] = [$url, $headers, json_decode($body, true)];

            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => [['index' => 0, 'status' => 'stored', 'id' => 4]]]])];
        }, 'https://console.test/api/', 'cnnk_engage');
        $event = ConsoleAuditOutbox::toEvent('bot.action', [], 'engage:audit_logs:1');
        $res = $outbox->send([$event]);

        $this->assertSame('https://console.test/api/portal/audit', $calls[0][0]);
        $this->assertContains('Authorization: Bearer cnnk_engage', $calls[0][1]);
        $this->assertSame(['events' => [$event]], $calls[0][2]);
        $this->assertSame('stored', $res['results'][0]['status']);
    }

    public function testNotConfiguredSendsNothing(): void
    {
        $called = false;
        $outbox = new ConsoleAuditOutbox(static function () use (&$called): array {
            $called = true;

            return ['status' => 200, 'body' => '{}'];
        }, '', 'k');
        $this->assertSame('not_configured', $outbox->record('bot.action', []));
        $this->assertFalse($called);
    }

    /**
     * Console serves portal/bot-approval-request and portal/bot-execution-result (Bot Orchestrator)
     * and portal/audit; it has never had portal/bot-mode-status, portal/bot-health or
     * portal/bot-reports, and portal/bot-audit wants a body Engage never sent.
     */
    public function testConsoleClientCallsOnlyRoutesConsoleServes(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(ConsoleClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        $this->assertSame(['__construct', 'reportExecution', 'requestApproval'], $methods);

        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php'
                && preg_match('#[\x27"]portal/bot-(mode-status|health|reports|audit)[\x27"]|->(sendAudit|sendModeStatus|sendHealth|sendReportsSummary)\(#', (string) file_get_contents($file->getPathname())) === 1) {
                $hits[] = substr($file->getPathname(), strlen(APPPATH));
            }
        }
        $this->assertSame([], $hits);
    }
}

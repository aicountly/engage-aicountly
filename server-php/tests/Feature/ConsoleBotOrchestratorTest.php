<?php

namespace Tests\Feature;

use App\Services\ConsoleAuditOutbox;
use App\Services\ConsoleClient;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\Support\DatabaseTestCase;

/**
 * Engage ↔ Console, against what Console serves:
 *   - audit goes to the central audit (POST /portal/audit) through engage_console_audit_outbox:
 *     delivered at once, retried with back-off, rejected kept, dead after 12 attempts, keyed by
 *     the engage_audit_logs row;
 *   - approvals use the Bot Orchestrator in Console's own shape (action_type …; Console's id from
 *     data.id), the execution result carries Console's approval id, and Console's execute callback
 *     (no `decision`) approves the request in Engage.
 *
 * @internal
 */
final class ConsoleBotOrchestratorTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    /** @var array{status:int, body:?string}|callable */
    private $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $this->next = $this->consoleAnswers('stored');
    }

    protected function tearDown(): void
    {
        foreach (['CONSOLE_API_URL', 'CONSOLE_API_TOKEN', 'ENGAGE_SERVICE_KEY'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        Services::reset(true);
        parent::tearDown();
    }

    private function outbox(): ConsoleAuditOutbox
    {
        return new ConsoleAuditOutbox(function (string $url, array $headers, string $body): array {
            $req = json_decode($body, true);
            $this->sent[] = $req;

            return is_callable($this->next) ? ($this->next)($req) : $this->next;
        }, 'https://console.test/api', 'cnnk_engage', Database::connect());
    }

    private function consoleAnswers(string $status): callable
    {
        return static function (array $req) use ($status): array {
            $results = [];
            foreach ($req['events'] as $i => $e) {
                $results[] = ['index' => $i, 'external_id' => $e['external_id'], 'status' => $status, 'id' => 900 + $i];
            }

            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => $results]])];
        };
    }

    /** @return array<string,mixed>|null */
    private function row(string $ext): ?array
    {
        return Database::connect()->query('SELECT * FROM engage_console_audit_outbox WHERE external_id = ?', [$ext])->getRowArray();
    }

    private function env(string $k, string $v): void
    {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }

    public function testOutboxDeliversRetriesRejectsAndGivesUp(): void
    {
        $this->assertSame('delivered', $this->outbox()->record('bot.action', ['subject_kind' => 'lead', 'subject_id' => '4'], 'engage:audit_logs:1'));
        $r1 = $this->row('engage:audit_logs:1');
        $this->assertSame(['delivered', null, 900], [$r1['status'], $r1['event'], (int) $r1['console_audit_id']]);
        $this->assertSame('duplicate', $this->outbox()->record('bot.action', [], 'engage:audit_logs:1'));

        $this->next = ['status' => 422, 'body' => '{"success":false,"message":"event_type is required."}'];
        $this->assertSame('pending', $this->outbox()->record('approval.requested', ['actor_email' => 'a@x.test'], 'engage:audit_logs:2'));
        $r2 = $this->row('engage:audit_logs:2');
        $this->assertSame(['pending', 1, 422], [$r2['status'], (int) $r2['attempts'], (int) $r2['last_http_status']]);
        $this->assertGreaterThan(time() + 60, strtotime((string) $r2['next_attempt_at']));

        Database::connect()->query("UPDATE engage_console_audit_outbox SET next_attempt_at = NOW() - INTERVAL '1 second' WHERE status = 'pending'");
        $this->sent = [];
        $this->next = $this->consoleAnswers('duplicate');
        $this->assertSame(1, $this->outbox()->deliverDue()['delivered']);
        $this->assertSame('a@x.test', $this->sent[0]['events'][0]['actor_email']);
        $this->assertSame('delivered', $this->row('engage:audit_logs:2')['status']);

        $this->next = static fn (array $req): array => ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => [
            ['index' => 0, 'status' => 'rejected', 'error' => 'action_required'],
        ]]])];
        $this->assertSame('rejected', $this->outbox()->record('x', [], 'engage:audit_logs:3'));

        $this->next = ['status' => 503, 'body' => null];
        $this->outbox()->record('credit.large', [], 'engage:audit_logs:4');
        Database::connect()->query("UPDATE engage_console_audit_outbox SET attempts = 11, next_attempt_at = NOW() - INTERVAL '1 second' WHERE external_id = 'engage:audit_logs:4'");
        $this->outbox()->deliverDue();
        $this->assertSame(['dead', 12], [$this->row('engage:audit_logs:4')['status'], (int) $this->row('engage:audit_logs:4')['attempts']]);
    }

    public function testAuditServiceQueuesFannedOutEventsByAuditLogId(): void
    {
        $this->env('CONSOLE_API_URL', 'http://127.0.0.1:9/api');   // nothing listens: the event stays queued
        $this->env('CONSOLE_API_TOKEN', 'cnnk_engage');

        Services::auditService()->log('credit.large', ['actor_email' => 'ravi@aicountly.com', 'subject_kind' => 'credit_note', 'subject_id' => 12, 'metadata' => ['amount' => 500000]]);
        Services::auditService()->log('product_update', ['subject_kind' => 'product', 'subject_id' => 1]);   // not fanned out

        $auditId = (int) Database::connect()->table('engage_audit_logs')->where('event', 'credit.large')->get()->getRow()->id;
        $row = $this->row('engage:audit_logs:' . $auditId);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $event = json_decode((string) $row['event'], true);
        $this->assertSame(['credit.large', 'engage', 'ravi@aicountly.com', 'credit_note', '12', ['amount' => 500000]],
            [$event['action'], $event['app_code'], $event['actor_email'], $event['entity_type'], $event['entity_id'], $event['metadata']['metadata']]);
        $this->assertSame(1, (int) Database::connect()->query('SELECT COUNT(*) AS n FROM engage_console_audit_outbox')->getRow()->n);
    }

    public function testApprovalsUseTheBotOrchestratorInConsolesShape(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(201, [], json_encode(['success' => true, 'message' => 'Approval queued.', 'data' => ['id' => 314, 'status' => 'pending']])),
            new Response(200, [], json_encode(['success' => true, 'data' => ['id' => 314, 'status' => 'executed']])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $this->env('CONSOLE_API_BASE_URL', 'https://console.test/api');
        $this->env('CONSOLE_INBOUND_KEY', 'portal-key');
        Services::injectMock('consoleClient', new ConsoleClient(new Client(['handler' => $stack, 'base_uri' => 'https://console.test/api/'])));

        $id = Services::approvalService()->request(['kind' => 'discount_apply', 'subject_kind' => 'proposal', 'subject_id' => 55,
            'risk_level' => 'high', 'payload' => ['percent' => 30]]);
        $req = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('/api/portal/bot-approval-request', $history[0]['request']->getUri()->getPath());
        $this->assertSame(['discount_apply', 'high', '55', 'engage'], [$req['action_type'], $req['risk_level'], $req['external_ref'], $req['source_module']]);
        $this->assertSame(['percent' => 30], $req['action_payload']['payload']);
        $row = Database::connect()->table('engage_approval_requests')->where('id', $id)->get()->getRowArray();
        $this->assertSame(['314', 'pending'], [(string) $row['console_approval_id'], $row['console_status']], "Console's approval id is kept (data.id)");

        // Console executes the approved approval: its callback has no `decision`.
        $this->env('ENGAGE_SERVICE_KEY', 'engage-service-key');
        $res = $this->withHeaders(['Authorization' => 'Bearer engage-service-key', 'X-Console-Portal' => 'console'])
            ->withBodyFormat('json')->post('v1/portal/bot/approval-callback', [
                'approval_id' => 314, 'correlation_id' => $row['correlation_id'], 'external_ref' => '55',
                'action_type' => 'discount_apply', 'action_payload' => [], 'decided_by' => 'pno@aicountly.com', 'decided_at' => '2026-10-14 10:00:00',
            ]);
        $res->assertStatus(200);
        $this->assertSame('approved', Database::connect()->table('engage_approval_requests')->where('id', $id)->get()->getRow()->status);

        $this->assertSame(['percent' => 30], (new \App\Models\ApprovalRequestsModel())->find($id)['payload'], 'payload stored once, read as an array');
        // A row an older release stored double-encoded still reads.
        Database::connect()->query("UPDATE engage_approval_requests SET payload = to_jsonb('{\"percent\":30}'::text) WHERE id = ?", [$id]);
        $this->assertSame(['percent' => 30], (new \App\Models\ApprovalRequestsModel())->find($id)['payload']);

        Services::approvalService()->executed($id, ['sent' => true]);
        $exec = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('/api/portal/bot-execution-result', $history[1]['request']->getUri()->getPath());
        $this->assertSame([314, true, ['sent' => true]], [$exec['approval_id'], $exec['success'], $exec['result']]);
    }
}

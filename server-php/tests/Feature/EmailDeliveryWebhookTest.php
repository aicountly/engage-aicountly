<?php

namespace Tests\Feature;

use App\Libraries\Email\AicountlyEmailTransport;
use App\Services\Email\EmailDeliveryLedger;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * POST v1/inbound/email/delivery-events end to end against the isolated database: signed events from the
 * AICOUNTLY Email API land in engage_email_deliveries once (dedupe on event_id), per recipient, without
 * regressing on late events; unsigned, stale or unconfigured calls change nothing. The send side records
 * the accepted message through the transport's default ledger.
 */
final class EmailDeliveryWebhookTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private const SECRET = 'whsec_feature_0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSecret(self::SECRET);
    }

    protected function tearDown(): void
    {
        $this->setSecret(null);
        parent::tearDown();
    }

    private function setSecret(?string $v): void
    {
        $k = 'AICOUNTLY_EMAIL_WEBHOOK_SECRET';
        if ($v === null) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        } else {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
    }

    private function deliver(array $event, ?int $ts = null, ?string $secret = self::SECRET, ?string $rawOverride = null)
    {
        $raw = $rawOverride ?? (string) json_encode($event, JSON_UNESCAPED_SLASHES);
        $ts ??= time();

        return $this->withHeaders([
            'Content-Type'          => 'application/json',
            'X-Aicountly-Timestamp' => (string) $ts,
            'X-Aicountly-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, (string) $secret),
        ])->withBody($raw)->post('v1/inbound/email/delivery-events');
    }

    private static function event(int $id, string $event, string $recipient, array $extra = []): array
    {
        return $extra + [
            'event_id' => $id, 'event' => $event, 'delivery_id' => 'dlv_100', 'idempotency_key' => 'engage.seller-activation-5',
            'category' => 'transactional', 'recipient' => $recipient, 'dsn' => null, 'bounce_class' => null, 'at' => '2026-11-16T10:00:0' . ($id % 10) . 'Z',
        ];
    }

    private function row(string $deliveryId = 'dlv_100'): array
    {
        $r = Database::connect()->query('SELECT * FROM engage_email_deliveries WHERE delivery_id = ?', [$deliveryId])->getRowArray();
        $this->assertNotNull($r);
        $r['recipients'] = json_decode((string) $r['recipients'], true);

        return $r;
    }

    private function eventCount(): int
    {
        return (int) Database::connect()->query('SELECT COUNT(*) AS n FROM engage_email_delivery_events')->getRow()->n;
    }

    public function testSignedEventsUpdateTheDeliveryOncePerEvent(): void
    {
        (new EmailDeliveryLedger())->recordSent([
            'idempotency_key' => 'engage.seller-activation-5', 'delivery_id' => 'dlv_100', 'category' => 'transactional',
            'template_ref' => 'engage.seller_activation', 'recipients' => ['A@example.com', 'b@example.com'], 'status' => 'queued',
        ]);
        $this->assertSame(['a@example.com' => ['status' => 'queued'], 'b@example.com' => ['status' => 'queued']], $this->row()['recipients']);

        $res = $this->deliver(self::event(1, 'relayed', 'a@example.com'));
        $res->assertStatus(200);
        $this->assertSame(['event_id' => 1, 'result' => 'applied'], json_decode($res->getJSON(), true)['data']);

        $this->deliver(self::event(2, 'bounced', 'b@example.com', ['dsn' => '5.1.1', 'bounce_class' => 'hard']))->assertStatus(200);

        $row = $this->row();
        $this->assertSame('bounced', $row['status']);
        $this->assertSame('bounced', $row['last_event']);
        $this->assertSame('relayed', $row['recipients']['a@example.com']['status']);
        // JSONB does not keep key order.
        $this->assertEquals(['status' => 'bounced', 'at' => '2026-11-16T10:00:02Z', 'dsn' => '5.1.1', 'bounce_class' => 'hard'], $row['recipients']['b@example.com']);

        // At least once: the same event_id again is acknowledged and changes nothing.
        $dup = $this->deliver(self::event(2, 'bounced', 'b@example.com', ['dsn' => '5.1.1', 'bounce_class' => 'hard']));
        $dup->assertStatus(200);
        $this->assertSame('duplicate', json_decode($dup->getJSON(), true)['data']['result']);
        $this->assertSame(2, $this->eventCount());

        // A late "deferred" for an address already relayed does not regress it.
        $this->deliver(self::event(3, 'deferred', 'a@example.com', ['dsn' => '4.2.0']))->assertStatus(200);
        $this->assertSame('relayed', $this->row()['recipients']['a@example.com']['status']);

        // failover is recorded, not a state.
        $this->deliver(self::event(4, 'failover', 'a@example.com'))->assertStatus(200);
        $row = $this->row();
        $this->assertSame('relayed', $row['recipients']['a@example.com']['status']);
        $this->assertSame('bounced', $row['status']);
        $this->assertSame(4, $this->eventCount());

        // A complaint after delivery is stronger than relayed.
        $this->deliver(self::event(5, 'complained', 'a@example.com'))->assertStatus(200);
        $row = $this->row();
        $this->assertSame('complained', $row['recipients']['a@example.com']['status']);
        $this->assertSame('complained', $row['status']);
        $this->assertSame('engage.seller_activation', $row['template_ref']);
    }

    public function testEventForAnUnrecordedDeliveryStartsItsRow(): void
    {
        $this->deliver(self::event(11, 'submitted', 'c@example.com', ['delivery_id' => 'dlv_200', 'idempotency_key' => 'engage.m.abc', 'category' => 'business']))->assertStatus(200);
        $row = $this->row('dlv_200');
        $this->assertSame('engage.m.abc', $row['idempotency_key']);
        $this->assertSame('business', $row['category']);
        $this->assertSame('sending', $row['status']);
        $this->assertEquals(['status' => 'sending', 'at' => '2026-11-16T10:00:01Z'], $row['recipients']['c@example.com']);
    }

    public function testUnsignedStaleOrUnconfiguredCallsChangeNothing(): void
    {
        $e = self::event(21, 'relayed', 'a@example.com');
        $this->deliver($e, null, 'wrong-secret')->assertStatus(401);
        $this->deliver($e, time() - 301)->assertStatus(401);
        $res = $this->withHeaders(['Content-Type' => 'application/json'])->withBody((string) json_encode($e))->post('v1/inbound/email/delivery-events');
        $res->assertStatus(401);
        $this->deliver([], null, self::SECRET, 'not json')->assertStatus(400);

        $this->setSecret(null);
        $this->deliver($e)->assertStatus(503);

        $this->assertSame(0, $this->eventCount());
        $this->assertSame(0, (int) Database::connect()->query('SELECT COUNT(*) AS n FROM engage_email_deliveries')->getRow()->n);
    }

    public function testAcceptedSendIsRecordedByTheDefaultLedger(): void
    {
        $t = new AicountlyEmailTransport(static fn (): array => [
            'status' => 202, 'body' => (string) json_encode(['data' => ['id' => 'dlv_300', 'category' => 'business', 'status' => 'queued', 'recipients' => []]]),
        ], static function (int $s): void {
        });
        $msg = ['to' => 'x@example.com', 'subject' => 'Invoice', 'html' => '', 'text' => 'Attached.', 'from_name' => 'Acme',
            'delivery' => ['idempotency_key' => 'invoice-300', 'cmp_id' => 42, 'from_domain' => 'acme.example', 'template_ref' => 'engage.invoice']];
        $provider = ['code' => 'aicountly_email', 'type' => 'aicountly_email', 'config' => ['base_url' => 'https://email.test/v1'], 'secret' => 'k'];

        $this->assertTrue($t->send($provider, $msg)['ok']);
        $this->assertTrue($t->send($provider, $msg)['ok'], 'recording the same key twice is harmless');
        $row = $this->row('dlv_300');
        $this->assertSame(['engage.invoice-300', 'business', '42', 'queued', 1], [$row['idempotency_key'], $row['category'], (string) $row['cmp_id'], $row['status'], (int) $row['recipient_count']]);
        $this->assertSame(['x@example.com' => ['status' => 'queued']], $row['recipients']);

        $this->deliver(self::event(31, 'relayed', 'x@example.com', ['delivery_id' => 'dlv_300', 'idempotency_key' => 'engage.invoice-300']))->assertStatus(200);
        $this->assertSame('relayed', $this->row('dlv_300')['recipients']['x@example.com']['status']);
    }
}

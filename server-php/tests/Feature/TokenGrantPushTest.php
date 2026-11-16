<?php

namespace Tests\Feature;

use App\Services\Tokens\TokenGrantPusher;
use App\Services\Tokens\TokenOrderService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\Support\LicensingTestCase;

/**
 * A paid order's credits reach AI Pulse — company credits with their company — and only then is the customer sent the
 * purchase receipt (Console trigger credits.purchase_receipt), with the invoice and what the bucket now holds.
 *
 * @internal
 */
final class TokenGrantPushTest extends LicensingTestCase
{
    private const CMP = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('PULSE_API_BASE_URL', 'https://pulse.example.test');
        $this->setEnv('ENGAGE_OUTBOUND_PULSE_KEY_ID', 'engage-grants-2026-11');
        $this->setEnv('ENGAGE_OUTBOUND_PULSE_SECRET', str_repeat('p', 40));
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'local');
        $this->setEnv('CONSOLE_API_URL', 'https://console.example.test/api');
        $this->setEnv('CONSOLE_API_TOKEN', 'console-token');
    }

    public function testCompanyCreditsReachTheirBucketAndTheReceiptFollows(): void
    {
        $orders = new TokenOrderService($this->db());
        $o = $orders->create(['tokens' => 1000000, 'scope' => 'company', 'company_name' => 'Acme Pvt Ltd'], 'holder-push-1', self::CMP,
            ['name' => 'Priya Sharma', 'email' => 'accounts@acme.example', 'state_code' => '27', 'mobile' => '9876543210'])['data'];
        $orders->settle($o['order_ref'], ['payment_ref' => 'pay_PUSH1', 'invoice_no' => 'AIC/26-27/000101', 'invoice_date' => '2026-11-16']);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['success' => true, 'data' => ['credited' => true, 'bucket' => 'company', 'bucket_tokens' => 1500000]])),
            new Response(200, [], (string) json_encode(['success' => true, 'data' => ['sent' => true]])),
        ]));
        $stack->push(Middleware::history($history));
        $r = (new TokenGrantPusher($this->db(), new Client(['handler' => $stack])))->drain();

        $this->assertSame(1, $r['sent']);
        $push = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame([self::CMP, 1000000, 'holder-push-1'], [$push['cmp_id'] ?? null, $push['tokens'], $push['holder_uuid']], 'company credits name their company');
        $this->assertSame('delivered', $orders->findByRef($o['order_ref'])['grant_status']);

        $this->assertStringEndsWith('/portal/notifications/send', (string) $history[1]['request']->getUri());
        $mail = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame(['credits.purchase_receipt', 'email', 'accounts@acme.example'], [$mail['trigger_key'], $mail['channel'], $mail['recipient']['email']]);
        $this->assertSame([
            'payer_name' => 'Priya Sharma', 'amount' => '₹1,180.00', 'company_name' => 'Acme Pvt Ltd', 'tokens_added' => '1,000,000',
            'tokens_left' => '1,500,000', 'invoice_no' => 'AIC/26-27/000101', 'invoice_date' => '2026-11-16',
        ], $mail['variables']);
    }

    public function testWorkspaceCreditsCarryNoCompany(): void
    {
        $orders = new TokenOrderService($this->db());
        $o = $orders->create(['tokens' => 1000000], 'holder-push-2', self::CMP,
            ['name' => 'Ravi', 'email' => 'ravi@example.test', 'state_code' => '27', 'mobile' => '9876543211'])['data'];
        $orders->settle($o['order_ref'], ['payment_ref' => 'pay_PUSH2']);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['success' => true, 'data' => ['credited' => true]])),
            new Response(200, [], (string) json_encode(['success' => true, 'data' => ['sent' => true]])),
        ]));
        $stack->push(Middleware::history($history));
        (new TokenGrantPusher($this->db(), new Client(['handler' => $stack])))->drain();

        $this->assertArrayNotHasKey('cmp_id', json_decode((string) $history[0]['request']->getBody(), true));
        $vars = json_decode((string) $history[1]['request']->getBody(), true)['variables'];
        $this->assertSame(['your company (credits for all your companies)', 'shown under Plans & billing', 'being issued'],
            [$vars['company_name'], $vars['tokens_left'], $vars['invoice_no']], 'no bucket figure from an older Pulse; invoice not yet known');
    }
}

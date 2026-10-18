<?php

namespace Tests\Feature;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\PayoutWeek;
use App\Libraries\Seller\SellerCrypto;
use App\Services\Payouts\FinancePayoutOutbox;
use Config\Services;
use Tests\Support\SellerTestCase;

/**
 * Weekly seller payouts: run idempotency per ISO week, rollover below the minimum, KYC / status gate, cutoff,
 * fail-closed configuration, Finance delivery (retry, reject, re-send, cancel), Finance status callbacks
 * (auth, transitions, paid needs a UTR, failed / reversed → entries back to approved), seller-portal payout
 * endpoints and staff role gates.
 */
final class SellerPayoutsTest extends SellerTestCase
{
    private const FINANCE_KEY = 'finance-inbound-key-0123456789abcdefghijkl';
    private const OUT_KEY     = 'engage-outbound-key-0123456789abcdefghijkl';
    private const PAN         = 'ABCPK1234F';
    private const ACCOUNT     = '123456789012';

    /** @var list<array{url:string, headers:list<string>, body:array}> */
    private array $calls = [];
    /** @var list<array{status:int, body:string}> answers in order; the last one repeats */
    private array $answers = [];
    private int $planId = 0;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('ENGAGE_INBOUND_FINANCE_KEY', self::FINANCE_KEY);
        $this->answers = [['status' => 201, 'body' => '{"ok":true,"data":{"batch_id":"FIN-B-1","status":"received"}}']];
        $this->injectFinance();
    }

    private function injectFinance(?string $base = 'https://finance.test/api', string $key = self::OUT_KEY): void
    {
        $transport = function (string $url, array $headers, string $body): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
            $a = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];

            return $a + ['error' => ''];
        };
        Services::injectMock('financePayouts', new FinancePayoutOutbox($transport, $base ?? '', $key));
        Services::injectMock('sellerPayouts', new \App\Services\Payouts\SellerPayoutService());
    }

    // ------------------------------------------------------------------ fixtures

    private function payableSeller(string $code, array $opt = []): int
    {
        $db = $this->db();
        $crypto = new SellerCrypto(self::DATA_KEY);
        $db->table('engage_sellers')->insert([
            'seller_code' => $code, 'name' => 'Seller ' . $code, 'email' => strtolower($code) . '@seller.example', 'entity_type' => 'individual',
            'status' => $opt['status'] ?? 'active', 'kyc_status' => $opt['kyc'] ?? 'kyc_verified',
        ]);
        $id = (int) $db->insertID();
        $db->table('engage_seller_kyc')->insert([
            'seller_id' => $id, 'legal_name' => 'Legal ' . $code, 'entity_type' => 'individual',
            'pan_enc' => $crypto->encrypt(self::PAN), 'pan_last4' => '234F',
            'gstin_enc' => isset($opt['gstin']) ? $crypto->encrypt($opt['gstin']) : null, 'gstin_last4' => isset($opt['gstin']) ? substr($opt['gstin'], -4) : null,
            'gst_state_code' => isset($opt['gstin']) ? substr($opt['gstin'], 0, 2) : null,
            'bank_account_holder' => 'Legal ' . $code, 'bank_account_enc' => $crypto->encrypt(self::ACCOUNT), 'bank_account_last4' => '9012', 'bank_ifsc' => 'HDFC0001234',
            'status' => $opt['kyc'] ?? 'kyc_verified',
        ]);

        return $id;
    }

    /** An approved commission entry of $amount, approved at $approvedTs. */
    private function entry(int $sellerId, string $amount, int $approvedTs): int
    {
        $db = $this->db();
        if ($this->planId === 0) {
            $db->table('engage_commission_plans')->insert(['plan_code' => 'std', 'name' => 'Standard', 'status' => 'active', 'rules' => '{"type":"flat_per_conversion","amount":"100.00"}']);
            $this->planId = (int) $db->insertID();
        }
        $n = ++$this->seq;
        $db->table('engage_leads')->insert(['name' => "Customer {$n}", 'lead_code' => sprintf('LD-T%05d', $n), 'seller_id' => $sellerId, 'stage' => 'converted', 'interested_product' => 'books']);
        $leadId = (int) $db->insertID();
        $db->table('engage_lead_payments')->insert(['lead_id' => $leadId, 'payment_ref' => "INV-{$n}", 'sequence_no' => 1, 'amount' => '10000.00', 'paid_at' => Clock::at($approvedTs - 20 * 86400)]);
        $payId = (int) $db->insertID();
        $db->table('engage_commission_entries')->insert([
            'seller_id' => $sellerId, 'lead_id' => $leadId, 'payment_id' => $payId, 'plan_id' => $this->planId, 'plan_code' => 'std', 'plan_version' => 1,
            'rule_type' => 'flat_per_conversion', 'product_code' => 'books', 'basis_amount' => '10000.00', 'amount' => $amount, 'status' => 'approved',
            'accrued_at' => Clock::at($approvedTs - 15 * 86400), 'hold_until' => Clock::at($approvedTs), 'approved_at' => Clock::at($approvedTs),
        ]);

        return (int) $db->insertID();
    }

    private static function inWeek(PayoutWeek $w, int $day = 2): int
    {
        return $w->start->getTimestamp() + $day * 86400 + 3600;
    }

    private function entryRow(int $id): array
    {
        return $this->db()->table('engage_commission_entries')->where('id', $id)->get()->getRowArray();
    }

    private function payout(string $ref): array
    {
        return $this->db()->table('engage_seller_payouts')->where('payout_ref', $ref)->get()->getRowArray();
    }

    private function finance(array $body, ?string $key = self::FINANCE_KEY)
    {
        $this->headers = [];
        $h = $key === null ? [] : ['X-Finance-Key' => $key];

        return $this->withHeaders($h)->withBodyFormat('json')->post('v1/inbound/finance/payout-status', $body);
    }

    private function st(string $itemRef, string $status, array $extra = []): array
    {
        return array_merge(['batch_ref' => substr($itemRef, 0, 3) === 'PO-' ? 'PB-' . substr($itemRef, 3, 7) : '', 'item_ref' => $itemRef, 'status' => $status,
            'tds_amount' => null, 'gst_amount' => null, 'net_amount' => null, 'utr' => null, 'paid_at' => null, 'reason' => null], $extra);
    }

    private function headerOf(array $call, string $name): ?string
    {
        foreach ($call['headers'] as $h) {
            if (stripos($h, $name . ':') === 0) {
                return trim(substr($h, strlen($name) + 1));
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ run

    public function testRunIsIdempotentPerWeekAndSendsTheDraftBatch(): void
    {
        $w = PayoutWeek::lastCompleted();
        $a = $this->payableSeller('SLR-000101', ['gstin' => '27ABCPK1234F1Z5']);
        $b = $this->payableSeller('SLR-000102');
        $e1 = $this->entry($a, '400.00', self::inWeek($w));
        $e2 = $this->entry($a, '350.50', self::inWeek($w, 4));
        $e3 = $this->entry($b, '900.00', self::inWeek($w));

        $r = Services::sellerPayouts()->run($w->code());
        $this->assertSame(201, $r['status']);
        $this->assertSame('delivered', $r['data']['delivery']);
        $run = $r['data']['run'];
        $this->assertSame('sent', $run['status']);
        $this->assertSame(2, $run['payouts_count']);
        $this->assertSame(3, $run['entries_count']);
        $this->assertSame('1650.50', $run['gross_amount']);
        $this->assertSame('FIN-B-1', $run['finance_batch_id']);

        // Exactly the contract's request.
        $this->assertCount(1, $this->calls);
        $call = $this->calls[0];
        $this->assertSame('https://finance.test/api/v1/inbound/engage/payout-batches', $call['url']);
        $this->assertSame(self::OUT_KEY, $this->headerOf($call, 'X-Engage-Key'));
        $this->assertSame($w->batchRef(), $this->headerOf($call, 'Idempotency-Key'));
        $body = $call['body'];
        $this->assertSame(['batch_ref', 'period_start', 'period_end', 'currency', 'items'], array_keys($body));
        $this->assertSame($w->batchRef(), $body['batch_ref']);
        $this->assertSame($w->periodStart(), $body['period_start']);
        $this->assertSame($w->periodEnd(), $body['period_end']);
        $this->assertSame('INR', $body['currency']);
        $itemA = $body['items'][0];
        $this->assertSame($w->payoutRef('SLR-000101'), $itemA['item_ref']);
        $this->assertSame('SLR-000101', $itemA['seller_code']);
        $this->assertSame('750.50', $itemA['gross_amount']);
        $this->assertSame([$e1, $e2], $itemA['entries']);
        $this->assertSame(['legal_name' => 'Legal SLR-000101', 'pan' => self::PAN, 'gstin' => '27ABCPK1234F1Z5', 'entity_type' => 'individual',
            'bank' => ['account_holder' => 'Legal SLR-000101', 'account_number' => self::ACCOUNT, 'ifsc' => 'HDFC0001234']], $itemA['payee']);
        $this->assertNull($body['items'][1]['payee']['gstin']);
        $this->assertSame([$e3], $body['items'][1]['entries']);

        // State: entries in_payout with the payout ref, payouts draft_sent, nothing decrypted at rest.
        $this->assertSame('in_payout', $this->entryRow($e1)['status']);
        $this->assertSame($w->payoutRef('SLR-000101'), $this->entryRow($e2)['payout_item_ref']);
        $p = $this->payout($w->payoutRef('SLR-000102'));
        $this->assertSame('draft_sent', $p['status']);
        $this->assertSame('FIN-B-1', $p['finance_batch_ref']);
        $this->assertNull($p['tds_amount']);
        foreach (['engage_finance_payout_outbox', 'engage_seller_payouts', 'engage_seller_payout_events', 'engage_payout_runs'] as $t) {
            $dump = json_encode($this->db()->table($t)->get()->getResultArray());
            $this->assertStringNotContainsString(self::PAN, $dump, $t);
            $this->assertStringNotContainsString(self::ACCOUNT, $dump, $t);
        }

        // Same week again: reported, not rebuilt, nothing sent.
        $again = Services::sellerPayouts()->run($w->code());
        $this->assertSame(200, $again['status']);
        $this->assertSame('exists', $again['data']['result']);
        $this->assertCount(1, $this->calls);
        $this->assertSame(2, $this->db()->table('engage_seller_payouts')->countAllResults());
        $this->assertSame(1, $this->db()->table('engage_payout_runs')->countAllResults());

        // The default week is the last one that ended.
        $this->assertSame('exists', Services::sellerPayouts()->run()['data']['result']);
    }

    public function testRolloverBelowMinimumKycAndStatusGateAndCutoff(): void
    {
        $w2 = PayoutWeek::lastCompleted();
        $w1 = PayoutWeek::containing($w2->start->getTimestamp() - 3 * 86400);
        $small = $this->payableSeller('SLR-000201');
        $noKyc = $this->payableSeller('SLR-000202', ['kyc' => 'kyc_submitted']);
        $susp  = $this->payableSeller('SLR-000203', ['status' => 'suspended']);
        $ok    = $this->payableSeller('SLR-000204');
        $s1 = $this->entry($small, '300.00', self::inWeek($w1));
        $k1 = $this->entry($noKyc, '5000.00', self::inWeek($w1));
        $u1 = $this->entry($susp, '5000.00', self::inWeek($w1));
        $o1 = $this->entry($ok, '500.00', self::inWeek($w1)); // exactly the minimum is paid
        $late = $this->entry($ok, '700.00', self::inWeek($w2)); // approved after w1 ended

        $r1 = Services::sellerPayouts()->run($w1->code())['data']['run'];
        $this->assertSame(1, $r1['payouts_count']);
        $this->assertSame('500.00', $r1['gross_amount']);
        $this->assertSame(1, $r1['below_minimum_count']);
        $this->assertSame('300.00', $r1['below_minimum_amount']);
        $this->assertSame(2, $r1['ineligible_count']);
        $this->assertSame('in_payout', $this->entryRow($o1)['status']);
        $this->assertSame('approved', $this->entryRow($late)['status'], 'cutoff: approved after the week ended');
        foreach ([$s1, $k1, $u1] as $id) {
            $this->assertSame('approved', $this->entryRow($id)['status']);
            $this->assertNull($this->entryRow($id)['payout_item_ref']);
        }

        // Next week: the small balance has grown past the minimum and rolls in; KYC / suspended stay held.
        $this->entry($small, '250.00', self::inWeek($w2));
        $pv = Services::sellerPayouts()->preview($w2->code());
        $this->assertTrue($pv['dry_run']);
        $bySeller = array_column($pv['sellers'], null, 'seller_code');
        $this->assertTrue($bySeller['SLR-000201']['included']);
        $this->assertSame('550.00', $bySeller['SLR-000201']['gross_amount']);
        $this->assertSame('kyc_not_verified', $bySeller['SLR-000202']['skip_reason']);
        $this->assertSame('seller_suspended', $bySeller['SLR-000203']['skip_reason']);
        $this->assertSame(0, $this->db()->table('engage_seller_payouts')->where('iso_week', $w2->code())->countAllResults(), 'preview writes nothing');

        $r2 = Services::sellerPayouts()->run($w2->code())['data']['run'];
        $this->assertSame(2, $r2['payouts_count']);
        $this->assertSame('1250.00', $r2['gross_amount']);
        $this->assertSame($w2->payoutRef('SLR-000201'), $this->entryRow($s1)['payout_item_ref']);
        $this->assertContains($s1, json_decode($this->payout($w2->payoutRef('SLR-000201'))['entry_ids'], true));
        $this->assertSame(2, (int) $this->payout($w2->payoutRef('SLR-000201'))['entries_count']);

        // An earlier week cannot be run after a later one.
        $w0 = PayoutWeek::containing($w1->start->getTimestamp() - 3 * 86400);
        $this->assertSame(409, Services::sellerPayouts()->run($w0->code())['status']);

        // Minimum from Settings.
        $this->db()->table('engage_settings')->where('key', 'seller_payout_min_threshold')->update(['value_json' => json_encode('6000.00')]);
        $pv3 = Services::sellerPayouts()->preview();
        $this->assertSame('6000.00', $pv3['min_payout']);
        $this->assertSame(PayoutWeek::current()->code(), $pv3['week'], 'default preview = next Monday\'s run');
    }

    public function testWeekNotEndedAndEmptyRun(): void
    {
        $r = Services::sellerPayouts()->run(PayoutWeek::current()->code());
        $this->assertSame(422, $r['status']);
        $this->assertSame(422, Services::sellerPayouts()->run('2026-W99')['status']);
        $empty = Services::sellerPayouts()->run();
        $this->assertSame(201, $empty['status']);
        $this->assertSame('empty', $empty['data']['run']['status']);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->db()->table('engage_finance_payout_outbox')->countAllResults());
    }

    public function testUnconfiguredFinanceFailsClosedAndTheWeekCanBeRunLater(): void
    {
        $w = PayoutWeek::lastCompleted();
        $e = $this->entry($this->payableSeller('SLR-000301'), '800.00', self::inWeek($w));
        $this->injectFinance(null);
        $r = Services::sellerPayouts()->run($w->code());
        $this->assertSame(503, $r['status']);
        $this->assertStringContainsString('FINANCE_API_BASE_URL', $r['error']);
        $run = $this->db()->table('engage_payout_runs')->get()->getRowArray();
        $this->assertSame('failed', $run['status']);
        $this->assertSame('approved', $this->entryRow($e)['status']);
        $this->assertSame(0, $this->db()->table('engage_seller_payouts')->countAllResults());

        $this->injectFinance('https://finance.test/api', 'short');
        $this->assertSame(503, Services::sellerPayouts()->run($w->code())['status']);

        $this->injectFinance();
        $ok = Services::sellerPayouts()->run($w->code());
        $this->assertSame(201, $ok['status']);
        $this->assertSame('sent', $ok['data']['run']['status']);
        $this->assertSame(3, $ok['data']['run']['attempts']);
        $this->assertSame(1, $this->db()->table('engage_payout_runs')->countAllResults());
    }

    public function testDeliveryRetryRejectResendAndCancel(): void
    {
        $w2 = PayoutWeek::lastCompleted();
        $w1 = PayoutWeek::containing($w2->start->getTimestamp() - 3 * 86400);
        $sid = $this->payableSeller('SLR-000401');
        $e = $this->entry($sid, '900.00', self::inWeek($w1));
        $this->answers = [['status' => 502, 'body' => 'bad gateway'], ['status' => 422, 'body' => '{"ok":false,"error":"payee bank invalid"}'],
            ['status' => 201, 'body' => '{"ok":true,"data":{"batch_id":"FIN-B-9","status":"received"}}']];

        $r = Services::sellerPayouts()->run($w1->code());
        $this->assertSame('retry', $r['data']['delivery']);
        $run = $r['data']['run'];
        $this->assertSame('queued', $run['status']);
        $this->assertSame('pending', $run['delivery']['status']);
        $this->assertSame('queued', $this->payout($w1->payoutRef('SLR-000401'))['status'], 'not draft_sent until Finance accepted it');

        $this->db()->query('UPDATE engage_finance_payout_outbox SET next_attempt_at = NOW()');
        $d = Services::financePayouts()->deliverDue();
        $this->assertSame(1, $d['rejected']);
        $run = Services::sellerPayouts()->runView(Services::sellerPayouts()->findRun($run['id']));
        $this->assertSame('delivery_failed', $run['status']);
        $this->assertStringContainsString('payee bank invalid', $run['error']);
        $this->assertTrue($run['can_resend']);

        // Re-send: super_admin only.
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['sales_manager'], 'sales@aicountly.org'), 'post', "v1/payout-runs/{$run['id']}/resend")));
        $admin = $this->staff(['super_admin'], 'admin@aicountly.org');
        $rs = $this->json($this->asStaff($admin, 'post', "v1/payout-runs/{$run['id']}/resend"))['data'];
        $this->assertSame('delivered', $rs['delivery']);
        $this->assertSame('sent', $rs['run']['status']);
        $this->assertSame('draft_sent', $this->payout($w1->payoutRef('SLR-000401'))['status']);
        $this->assertCount(3, $this->calls);
        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', "v1/payout-runs/{$run['id']}/resend")), 'nothing to re-send');
        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', "v1/payout-runs/{$run['id']}/cancel", ['reason' => 'too late'])), 'Finance has it');

        // A batch Finance never accepted can be cancelled: entries go back to approved.
        $e2 = $this->entry($sid, '600.00', self::inWeek($w2));
        $this->answers = [['status' => 400, 'body' => '{"ok":false,"error":"bad"}']];
        $run2 = Services::sellerPayouts()->run($w2->code())['data']['run'];
        $this->assertSame('delivery_failed', $run2['status']);
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/payout-runs/{$run2['id']}/cancel", ['reason' => ''])));
        $c = $this->json($this->asStaff($admin, 'post', "v1/payout-runs/{$run2['id']}/cancel", ['reason' => 'Bank details to be fixed']))['data'];
        $this->assertSame(1, $c['entries_returned']);
        $this->assertSame('cancelled', $c['run']['status']);
        $this->assertSame('approved', $this->entryRow($e2)['status']);
        $this->assertNull($this->entryRow($e2)['payout_item_ref']);
        $this->assertSame('cancelled', $this->payout($w2->payoutRef('SLR-000401'))['status']);
        $this->assertSame('in_payout', $this->entryRow($e)['status']);
    }

    /**
     * FINANCE_API_BASE_URL without /api reaches finance.aicountly.org's SPA fallback, which answers any POST with
     * 200 index.html. That is not Finance's receipt: the batch must not be reported sent (found end to end).
     */
    public function testTwoHundredWithoutFinanceReceiptIsNotDelivered(): void
    {
        $w = PayoutWeek::lastCompleted();
        $sid = $this->payableSeller('SLR-000451');
        $this->entry($sid, '900.00', self::inWeek($w));
        $spa = ['status' => 200, 'body' => '<!doctype html><html><body><div id="root"></div></body></html>', 'content_type' => 'text/html; charset=utf-8'];
        $this->answers = [$spa, ['status' => 200, 'body' => '{"ok":true,"data":{"status":"received"}}'],
            ['status' => 200, 'body' => '{"success":true,"message":"Batch already received.","data":{"batch_id":7,"status":"received","duplicate":true}}']];

        $r = Services::sellerPayouts()->run($w->code());
        $this->assertSame('rejected', $r['data']['delivery']);
        $run = $r['data']['run'];
        $this->assertSame('delivery_failed', $run['status']);
        $this->assertStringContainsString('FINANCE_API_BASE_URL', $run['error']);
        $this->assertStringContainsString('text/html', $run['error']);
        $this->assertSame('queued', $this->payout($w->payoutRef('SLR-000451'))['status'], 'not draft_sent: Finance never answered');
        $this->assertSame('rejected', Services::financePayouts()->forRun($run['id'])['status']);

        // JSON 2xx without a batch_id is not a receipt either.
        $admin = $this->staff(['super_admin'], 'admin@aicountly.org');
        $this->assertSame('rejected', $this->json($this->asStaff($admin, 'post', "v1/payout-runs/{$run['id']}/resend"))['data']['delivery']);

        // Finance's real envelope ({success, message, data:{batch_id, …, duplicate}}) is delivered.
        $rs = $this->json($this->asStaff($admin, 'post', "v1/payout-runs/{$run['id']}/resend"))['data'];
        $this->assertSame('delivered', $rs['delivery']);
        $this->assertSame('sent', $rs['run']['status']);
        $this->assertSame('7', (string) Services::financePayouts()->forRun($run['id'])['finance_batch_id']);
        $this->assertSame('draft_sent', $this->payout($w->payoutRef('SLR-000451'))['status']);
    }

    /** Build last week's run for one payable seller with the given Finance answers; returns [run view, entry id, admin token]. */
    private function runAnswered(array $answers, bool $deliver = true): array
    {
        $w = PayoutWeek::lastCompleted();
        $sid = $this->payableSeller('SLR-000471');
        $e = $this->entry($sid, '900.00', self::inWeek($w));
        $this->answers = $answers;
        $run = Services::sellerPayouts()->run($w->code(), 'schedule', null, $deliver)['data']['run'];

        return [$run, $e, $this->staff(['super_admin'], 'admin@aicountly.org')];
    }

    private function cancel(string $token, int $runId)
    {
        return $this->asStaff($token, 'post', "v1/payout-runs/{$runId}/cancel", ['reason' => 'Bank details to be fixed']);
    }

    /** Finance's 409 = it already knows this batch_ref: Engage must not cancel (Finance may pay it). */
    public function testCancelRefusedAfterFinance409(): void
    {
        [$run, $e, $admin] = $this->runAnswered([['status' => 409, 'body' => '{"success":false,"message":"batch_ref PB-2026-41 was already received with different content."}']]);
        $this->assertSame('delivery_failed', $run['status']);
        $this->assertSame('rejected', $run['delivery']['status']);
        $this->assertTrue($run['can_resend']);
        $this->assertFalse($run['can_cancel']);
        $this->assertStringContainsString('cancel it in Finance', $run['cancel_blocked_reason']);
        $r = $this->cancel($admin, $run['id']);
        $this->assertSame(409, $this->code($r));
        $this->assertStringContainsString('cancel it in Finance', $this->json($r)['error']);
        $this->assertSame('in_payout', $this->entryRow($e)['status'], 'entries stay reserved');
        $this->assertSame('rejected', Services::financePayouts()->forRun($run['id'])['status']);
    }

    /** A lost / unknown answer (502) makes it sticky: a later explicit 422 does not make the batch cancellable. */
    public function testCancelRefusedAfterLostAnswerEvenIfLaterRejected(): void
    {
        [$run, $e, $admin] = $this->runAnswered([['status' => 502, 'body' => 'bad gateway'], ['status' => 422, 'body' => '{"success":false,"message":"invalid"}']]);
        $this->assertSame(409, $this->code($this->cancel($admin, $run['id'])), 'pending after a 502');
        $this->db()->query('UPDATE engage_finance_payout_outbox SET next_attempt_at = NOW()');
        $this->assertSame(1, Services::financePayouts()->deliverDue()['rejected']);
        $this->assertSame(409, $this->code($this->cancel($admin, $run['id'])), 'still refused after the 422');
        $this->assertSame('in_payout', $this->entryRow($e)['status']);
        // A dead outbox is refused too.
        $this->db()->query("UPDATE engage_finance_payout_outbox SET status = 'dead', finance_may_hold = FALSE");
        $this->assertSame(409, $this->code($this->cancel($admin, $run['id'])), 'dead');
    }

    public function testCancelAllowedAfterExplicit422(): void
    {
        [$run, $e, $admin] = $this->runAnswered([['status' => 422, 'body' => '{"success":false,"message":"The payout batch is invalid; nothing was stored."}']]);
        $this->assertTrue($run['can_cancel']);
        $this->assertNull($run['cancel_blocked_reason']);
        $r = $this->cancel($admin, $run['id']);
        $this->assertSame(200, $this->code($r));
        $this->assertSame(1, $this->json($r)['data']['entries_returned']);
        $this->assertSame('approved', $this->entryRow($e)['status']);
    }

    public function testCancelAllowedWhenNothingEverReachedFinance(): void
    {
        // Never attempted.
        [$run, $e, $admin] = $this->runAnswered([['status' => 201, 'body' => '{"ok":true,"data":{"batch_id":"X","status":"received"}}']], false);
        $this->assertSame(0, $run['delivery']['attempts']);
        $this->assertTrue($run['can_cancel']);
        // Connection refused / DNS (curl errno 7 / 6) and the SPA fallback (200 text/html) prove Finance has nothing.
        $this->answers = [['status' => 0, 'body' => false, 'error' => 'Failed to connect', 'errno' => 7], ['status' => 0, 'body' => false, 'error' => 'Could not resolve host', 'errno' => 6],
            ['status' => 200, 'body' => '<!doctype html><html></html>', 'content_type' => 'text/html']];
        $this->assertSame('retry', Services::financePayouts()->deliverRun($run['id']));
        $this->db()->query('UPDATE engage_finance_payout_outbox SET next_attempt_at = NOW()');
        Services::financePayouts()->deliverDue();
        $this->db()->query('UPDATE engage_finance_payout_outbox SET next_attempt_at = NOW()');
        $this->assertSame(1, Services::financePayouts()->deliverDue()['rejected'], 'SPA answer rejected');
        $this->assertSame(3, (int) Services::financePayouts()->forRun($run['id'])['attempts']);
        $this->assertSame(200, $this->code($this->cancel($admin, $run['id'])));
        $this->assertSame('approved', $this->entryRow($e)['status']);
        // A timeout (errno 28) may have reached Finance.
        $this->assertTrue(FinancePayoutOutbox::financeMayHold(0, null, 28));
        $this->assertTrue(FinancePayoutOutbox::financeMayHold(503, null));
        $this->assertTrue(FinancePayoutOutbox::financeMayHold(201, ['success' => true, 'data' => ['batch_id' => 4]]));
        $this->assertFalse(FinancePayoutOutbox::financeMayHold(400, null));
    }

    // ------------------------------------------------------------------ Finance status

    public function testFinanceStatusAuth(): void
    {
        $this->setEnv('ENGAGE_INBOUND_FINANCE_KEY', null);
        $this->assertSame(503, $this->code($this->finance(['item_ref' => 'x'])));
        $this->setEnv('ENGAGE_INBOUND_FINANCE_KEY', 'too-short');
        $this->assertSame(503, $this->code($this->finance(['item_ref' => 'x'], 'too-short')));
        $this->setEnv('ENGAGE_INBOUND_FINANCE_KEY', self::FINANCE_KEY);
        $this->assertSame(401, $this->code($this->finance(['item_ref' => 'x'], null)));
        $wrong = $this->finance(['item_ref' => 'x'], self::FINANCE_KEY . 'x');
        $this->assertSame(401, $this->code($wrong));
        $this->assertSame('finance_key_invalid', $this->json($wrong)['code']);
        $this->assertSame(404, $this->code($this->finance($this->st('PO-2026-01-000001', 'approved'))));
        $this->assertSame(422, $this->code($this->finance(['item_ref' => 'PO-1', 'batch_ref' => 'PB-1', 'status' => 'done'])));
        $this->assertSame(422, $this->code($this->finance(['item_ref' => ['x'], 'batch_ref' => 'PB-1', 'status' => 'paid', 'utr' => ['y'], 'net_amount' => ['1']])));
        $this->assertSame(422, $this->code($this->finance(['item_ref' => 'PO-1', 'batch_ref' => 'PB-1', 'status' => 'approved', 'tds_amount' => '-5.00'])));
        $this->headers = [];
        $this->assertSame(400, $this->code($this->withHeaders(['X-Finance-Key' => self::FINANCE_KEY, 'Content-Type' => 'application/json'])->call('post', 'v1/inbound/finance/payout-status')));
    }

    public function testFinanceStatusTransitionsPaidNeedsUtrAndReversalReturnsEntries(): void
    {
        $w = PayoutWeek::lastCompleted();
        $sid = $this->payableSeller('SLR-000501');
        $e1 = $this->entry($sid, '12000.00', self::inWeek($w));
        $e2 = $this->entry($sid, '500.00', self::inWeek($w));
        Services::sellerPayouts()->run($w->code());
        $ref = $w->payoutRef('SLR-000501');

        $wrongBatch = $this->finance(['batch_ref' => 'PB-1999-01'] + $this->st($ref, 'approved'));
        $this->assertSame(422, $this->code($wrongBatch));

        $ap = $this->finance($this->st($ref, 'approved', ['tds_amount' => '250.00', 'gst_amount' => '2250.00', 'net_amount' => '14500.00']));
        $this->assertSame(200, $this->code($ap));
        $this->assertSame('updated', $this->json($ap)['data']['result']);
        $p = $this->payout($ref);
        $this->assertSame('approved', $p['status']);
        $this->assertSame('14500.00', $p['net_amount']);
        $this->assertSame('unchanged', $this->json($this->finance($this->st($ref, 'approved', ['tds_amount' => '250.00', 'gst_amount' => '2250.00', 'net_amount' => '14500.00'])))['data']['result']);

        $hold = $this->finance($this->st($ref, 'on_hold', ['reason' => 'PAN-bank name mismatch']));
        $this->assertSame('on_hold', $this->json($hold)['data']['status']);
        $this->assertSame('14500.00', $this->payout($ref)['net_amount'], 'null amounts keep what Finance said before');

        $noUtr = $this->finance($this->st($ref, 'paid', ['net_amount' => '14500.00']));
        $this->assertSame(422, $this->code($noUtr));
        $this->assertArrayHasKey('utr', $this->json($noUtr)['details']);
        $this->assertSame('on_hold', $this->payout($ref)['status']);
        $this->assertSame('in_payout', $this->entryRow($e1)['status']);

        $paid = $this->finance($this->st($ref, 'paid', ['tds_amount' => '250.00', 'gst_amount' => '2250.00', 'net_amount' => '14500.00',
            'utr' => 'HDFCN52026101400012', 'paid_at' => gmdate('c', time() - 3600)]));
        $this->assertSame(200, $this->code($paid));
        $this->assertSame(2, $this->json($paid)['data']['entries_paid']);
        $p = $this->payout($ref);
        $this->assertSame('paid', $p['status']);
        $this->assertSame('HDFCN52026101400012', $p['utr']);
        $this->assertSame('paid', $this->entryRow($e1)['status']);
        $this->assertNotNull($this->entryRow($e2)['paid_at']);

        // Repeat → unchanged; a different UTR → conflict; a late duplicate of an older status → unchanged.
        $this->assertSame('unchanged', $this->json($this->finance($this->st($ref, 'paid', ['tds_amount' => '250.00', 'gst_amount' => '2250.00', 'net_amount' => '14500.00',
            'utr' => 'HDFCN52026101400012', 'paid_at' => gmdate('c', time() - 3600)])))['data']['result']);
        $this->assertSame(409, $this->code($this->finance($this->st($ref, 'paid', ['utr' => 'OTHER0000001']))));
        $this->assertSame('unchanged', $this->json($this->finance($this->st($ref, 'on_hold', ['reason' => 'PAN-bank name mismatch'])))['data']['result']);
        $this->assertSame(409, $this->code($this->finance($this->st($ref, 'failed'))), 'paid cannot fail; only reversed');

        // Reversed after payment (bank return): entries back to approved, out of the payout.
        $rev = $this->json($this->finance($this->st($ref, 'reversed', ['reason' => 'Account closed', 'utr' => 'HDFCN52026101400012'])))['data'];
        $this->assertSame(2, $rev['entries_returned']);
        foreach ([$e1, $e2] as $id) {
            $row = $this->entryRow($id);
            $this->assertSame('approved', $row['status']);
            $this->assertNull($row['payout_item_ref']);
            $this->assertNull($row['paid_at']);
        }
        $this->assertSame(409, $this->code($this->finance($this->st($ref, 'paid', ['utr' => 'HDFCN52026101400013']))), 'reversed is final');
        $this->assertSame(4, $this->db()->table('engage_seller_payout_events')->where('source', 'finance')->countAllResults(), 'approved, on_hold, paid, reversed');
    }

    public function testFailedPayoutReturnsEntriesThatRollIntoALaterRun(): void
    {
        $w2 = PayoutWeek::lastCompleted();
        $w1 = PayoutWeek::containing($w2->start->getTimestamp() - 3 * 86400);
        $sid = $this->payableSeller('SLR-000601');
        $e = $this->entry($sid, '700.00', self::inWeek($w1));
        Services::sellerPayouts()->run($w1->code());
        $ref1 = $w1->payoutRef('SLR-000601');

        $f = $this->json($this->finance($this->st($ref1, 'failed', ['reason' => 'IMPS rejected'])))['data'];
        $this->assertSame(1, $f['entries_returned']);
        $this->assertSame('failed', $this->payout($ref1)['status']);
        $this->assertSame('approved', $this->entryRow($e)['status']);
        $this->assertSame(409, $this->code($this->finance($this->st($ref1, 'paid', ['utr' => 'UTR00000001']))), 'a failed payout is never paid');

        // Staff: a failed payout's entries can now be reversed again (they are approved).
        $this->assertSame(1, $this->db()->table('engage_commission_entries')->where('status', 'approved')->countAllResults());

        $r2 = Services::sellerPayouts()->run($w2->code())['data']['run'];
        $this->assertSame(1, $r2['payouts_count']);
        $this->assertSame('700.00', $r2['gross_amount']);
        $this->assertSame($w2->payoutRef('SLR-000601'), $this->entryRow($e)['payout_item_ref']);

        $c = $this->json($this->finance($this->st($w2->payoutRef('SLR-000601'), 'cancelled', ['reason' => 'Duplicate'])))['data'];
        $this->assertSame(1, $c['entries_returned']);
        $this->assertSame('approved', $this->entryRow($e)['status']);
    }

    public function testInPayoutEntryCannotBeReversedByStaff(): void
    {
        $w = PayoutWeek::lastCompleted();
        $e = $this->entry($this->payableSeller('SLR-000701'), '700.00', self::inWeek($w));
        Services::sellerPayouts()->run($w->code());
        $r = $this->asStaff($this->staff(['sales_manager'], 'sales@aicountly.org'), 'post', "v1/commission-entries/{$e}/reverse", ['reason' => 'Refund']);
        $this->assertSame(409, $this->code($r));
        $this->assertStringContainsString($w->payoutRef('SLR-000701'), $this->json($r)['error']);
    }

    // ------------------------------------------------------------------ seller portal + staff API

    public function testSellerPortalPayoutEndpoints(): void
    {
        $s = $this->activeSellerSession('ravi@example.com');
        $other = $this->activeSellerSession('other@example.com');
        $empty = $this->json($this->portal('get', 'payouts', [], $s['session']))['data'];
        $this->assertSame(['items' => [], 'total' => 0, 'page' => 1, 'limit' => 20], $empty);
        $this->assertArrayNotHasKey('enabled', $empty);
        $this->assertNull($this->json($this->portal('get', 'dashboard', [], $s['session']))['data']['next_payout_date'], 'KYC not verified yet');

        // KYC verified (directly), commission approved last week, run.
        $crypto = new SellerCrypto(self::DATA_KEY);
        foreach ([$s, $other] as $x) {
            $this->db()->table('engage_seller_kyc')->insert(['seller_id' => $x['seller_id'], 'legal_name' => 'Ravi Kumar', 'entity_type' => 'individual',
                'pan_enc' => $crypto->encrypt(self::PAN), 'pan_last4' => '234F', 'bank_account_holder' => 'Ravi Kumar',
                'bank_account_enc' => $crypto->encrypt(self::ACCOUNT), 'bank_account_last4' => '9012', 'bank_ifsc' => 'HDFC0001234', 'status' => 'kyc_verified']);
            $this->db()->table('engage_sellers')->where('id', $x['seller_id'])->update(['status' => 'active', 'kyc_status' => 'kyc_verified']);
        }
        $d = $this->json($this->portal('get', 'dashboard', [], $s['session']))['data'];
        $this->assertSame(PayoutWeek::nextRunDate(), $d['next_payout_date']);
        $this->assertSame('Monday', (new \DateTimeImmutable($d['next_payout_date']))->format('l'));

        $w = PayoutWeek::lastCompleted();
        $e = $this->entry($s['seller_id'], '1200.00', self::inWeek($w));
        Services::sellerPayouts()->run($w->code());
        $ref = $w->payoutRef($s['seller_code']);

        $list = $this->json($this->portal('get', 'payouts', [], $s['session']))['data'];
        $this->assertSame(1, $list['total']);
        $item = $list['items'][0];
        $this->assertSame(['payout_ref', 'batch_ref', 'period_start', 'period_end', 'gross_amount', 'tds_amount', 'gst_amount', 'net_amount', 'utr', 'status', 'paid_at', 'reason', 'entries_count'], array_keys($item));
        $this->assertSame($ref, $item['payout_ref']);
        $this->assertSame('processing', $item['status']);
        $this->assertSame('1200.00', $item['gross_amount']);
        $this->assertNull($item['tds_amount']);
        $this->assertNull($item['net_amount']);
        $this->assertSame(1, $this->json($this->portal('get', 'payouts', ['status' => 'processing'], $s['session']))['data']['total']);
        $this->assertSame(0, $this->json($this->portal('get', 'payouts', ['status' => 'paid'], $s['session']))['data']['total']);
        $this->assertSame(0, $this->json($this->portal('get', 'payouts', [], $other['session']))['data']['total']);

        $this->finance($this->st($ref, 'paid', ['tds_amount' => '24.00', 'gst_amount' => '0.00', 'net_amount' => '1176.00', 'utr' => 'HDFCN52026101400012']));
        $detail = $this->json($this->portal('get', 'payouts/' . $ref, [], $s['session']))['data']['payout'];
        $this->assertSame('paid', $detail['status']);
        $this->assertSame('1176.00', $detail['net_amount']);
        $this->assertSame('HDFCN52026101400012', $detail['utr']);
        $this->assertSame('194H', $detail['tds_section']);
        $this->assertCount(1, $detail['entries']);
        $this->assertSame(['id', 'lead_code', 'product', 'amount', 'accrued_at'], array_keys($detail['entries'][0]));
        $this->assertSame(['CE-' . $e, 'AICOUNTLY Books', '1200.00'], [$detail['entries'][0]['id'], $detail['entries'][0]['product'], $detail['entries'][0]['amount']]);
        $this->assertSame(['legal_name' => 'Ravi Kumar', 'seller_code' => $s['seller_code'], 'entity_type' => 'individual', 'pan_masked' => 'XXXXXX234F', 'gstin_masked' => null], $detail['payee']);
        $this->assertNull($detail['issuer']);
        $this->assertStringNotContainsString(self::PAN, json_encode($detail));
        $this->assertSame('paid', $this->json($this->portal('get', 'commissions', [], $s['session']))['data']['items'][0]['status']);
        $this->assertSame('1200.00', $this->json($this->portal('get', 'dashboard', [], $s['session']))['data']['paid_total']);

        $this->setEnv('PAYOUT_ISSUER_NAME', 'AICOUNTLY <Pvt> Ltd');
        $st = $this->portal('get', 'payouts/' . $ref . '/statement', [], $s['session']);
        $this->assertSame(200, $this->code($st));
        $this->assertStringStartsWith('text/html', $st->response()->getHeaderLine('Content-Type'));
        $html = (string) $st->response()->getBody();
        $this->assertStringContainsString($ref, $html);
        $this->assertStringContainsString('HDFCN52026101400012', $html);
        $this->assertStringContainsString('₹1176.00', $html);
        $this->assertStringContainsString('section 194H', $html);
        $this->assertStringContainsString('AICOUNTLY &lt;Pvt&gt; Ltd', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString(self::PAN, $html);

        // Another seller's payout / unknown ref → 404; no session → 401.
        $this->assertSame(404, $this->code($this->portal('get', 'payouts/' . $ref, [], $other['session'])));
        $this->assertSame('not_found', $this->json($this->portal('get', 'payouts/' . $ref . '/statement', [], $other['session']))['code']);
        $this->assertSame(404, $this->code($this->portal('get', 'payouts/PO-0000-00-000000', [], $s['session'])));
        $this->assertSame(401, $this->code($this->portal('get', 'payouts')));
        $this->assertSame(401, $this->code($this->portal('get', 'payouts/' . $ref . '/statement')));
    }

    public function testStaffApiAndRoleGates(): void
    {
        $w = PayoutWeek::lastCompleted();
        $sid = $this->payableSeller('SLR-000801');
        $this->entry($sid, '900.00', self::inWeek($w));
        $this->entry($sid, '100.00', time() - 60); // this week: only in the preview

        foreach (['member', 'seller_manager', 'kyc_reviewer'] as $i => $role) {
            $t = $this->staff([$role], "r{$i}@aicountly.org");
            $this->assertSame(403, $this->code($this->asStaff($t, 'get', 'v1/payout-runs')), $role);
            $this->assertSame(403, $this->code($this->asStaff($t, 'get', 'v1/seller-payouts')), $role);
        }
        $sales = $this->staff(['sales_manager'], 'sales@aicountly.org');
        $list = $this->json($this->asStaff($sales, 'get', 'v1/payout-runs'))['data'];
        $this->assertSame([], $list['items']);
        $this->assertTrue($list['finance']['configured']);
        $this->assertSame('ok', $list['finance']['inbound_key']);
        $this->assertSame(PayoutWeek::nextRunDate(), $list['schedule']['next_run_date']);
        $pv = $this->json($this->asStaff($sales, 'get', 'v1/payout-runs/preview'))['data'];
        $this->assertSame(PayoutWeek::current()->code(), $pv['week']);
        $this->assertSame('1000.00', $pv['sellers'][0]['gross_amount'], 'preview of next Monday includes everything approved so far');
        $this->assertSame(422, $this->code($this->asStaff($sales, 'get', 'v1/payout-runs/preview?week=nope')));
        $this->assertSame(403, $this->code($this->asStaff($sales, 'post', 'v1/payout-runs', ['week' => $w->code()])));

        $admin = $this->staff(['super_admin'], 'admin@aicountly.org');
        $created = $this->asStaff($admin, 'post', 'v1/payout-runs', ['week' => $w->code()]);
        $this->assertSame(201, $this->code($created));
        $run = $this->json($created)['data']['run'];
        $this->assertSame('manual', $run['trigger']);
        $this->assertSame('900.00', $run['gross_amount']);
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', 'v1/payout-runs', ['week' => $w->code()])), 'idempotent');
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', 'v1/payout-runs', ['week' => PayoutWeek::current()->code()])));

        $show = $this->json($this->asStaff($sales, 'get', "v1/payout-runs/{$run['id']}"))['data'];
        $this->assertCount(1, $show['payouts']);
        $pid = $show['payouts'][0]['id'];
        $this->assertSame('draft_sent', $show['payouts'][0]['status']);
        $this->finance($this->st($w->payoutRef('SLR-000801'), 'on_hold', ['reason' => 'Review']));
        $one = $this->json($this->asStaff($sales, 'get', "v1/seller-payouts/{$pid}"))['data'];
        $this->assertSame('on_hold', $one['status']);
        $this->assertSame(['queued', 'draft_sent', 'on_hold'], array_column($one['events'], 'to'));
        $this->assertSame(1, $this->json($this->asStaff($sales, 'get', "v1/seller-payouts?seller_id={$sid}&status=on_hold"))['data']['total']);
        $this->assertSame(404, $this->code($this->asStaff($sales, 'get', 'v1/seller-payouts/999999')));
    }
}

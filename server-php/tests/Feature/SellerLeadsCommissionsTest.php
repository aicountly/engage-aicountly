<?php

namespace Tests\Feature;

use App\Services\ReachOutcomeOutbox;
use Config\Services;
use Tests\Support\SellerTestCase;

/**
 * Seller lead registration + protection, Reach intake de-duplication / referral attribution,
 * commission plans and entries, and the Reach outcome outbox.
 */
final class SellerLeadsCommissionsTest extends SellerTestCase
{
    private const REACH_TOKEN = 'reach-inbound-token-0123456789abcdef';

    /** @var list<array{url:string, headers:list<string>, body:array}> */
    private array $reachCalls = [];
    /** @var array{status:int, body:string} */
    private array $reachAnswer = ['status' => 200, 'body' => '{"ok":true,"data":{}}'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('REACH_INBOUND_TOKEN', self::REACH_TOKEN);
        $this->injectOutbox();
    }

    private function injectOutbox(): void
    {
        $transport = function (string $url, array $headers, string $body): array {
            $this->reachCalls[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];

            return $this->reachAnswer;
        };
        Services::injectMock('reachOutcomes', new ReachOutcomeOutbox($transport, 'https://reach.test/api', str_repeat('t', 40)));
    }

    private function reach(array $payload)
    {
        $this->headers = [];

        return $this->withHeaders(['X-Portal-Token' => self::REACH_TOKEN])->withBodyFormat('json')->post('v1/internal/reach/leads', array_merge([
            'source_portal' => 'reach.aicountly.org', 'name' => 'Meena Shah', 'campaign_code' => 'diwali-2026', 'campaign_name' => 'Diwali 2026',
        ], $payload));
    }

    private function lead(string $code): array
    {
        return $this->db()->table('engage_leads')->where('lead_code', $code)->get()->getRowArray();
    }

    // ------------------------------------------------------------------ registration

    public function testRegistrationProtectionAndDuplicates(): void
    {
        $a = $this->activeSellerSession('a@seller.example');
        $b = $this->activeSellerSession('b@seller.example');

        $r = $this->portal('post', 'leads', ['name' => 'Meena Shah', 'company' => 'Shah Traders', 'email' => 'Meena@Shah.example', 'mobile' => '+91 98200 11111',
            'interest' => 'books', 'consent_confirmed' => true], $a['session']);
        $this->assertSame(201, $this->code($r));
        $lead = $this->json($r)['data']['lead'];
        $this->assertSame('registered', $lead['status']);
        $this->assertSame(gmdate('Y-m-d', time() + 90 * 86400 + 19800), $lead['protected_until']);
        $row = $this->lead($lead['lead_code']);
        $this->assertSame('seller', $row['source_type']);
        $this->assertSame($a['seller_id'], (int) $row['seller_id']);
        $this->assertSame('books', $row['interested_product']);

        $dup = $this->portal('post', 'leads', ['name' => 'Meena', 'email' => 'meena@shah.example', 'consent_confirmed' => true], $a['session']);
        $this->assertSame(409, $this->code($dup));
        $this->assertSame('duplicate_lead', $this->json($dup)['code']);
        $this->assertStringContainsString('protected for you until', $this->json($dup)['error']);
        $this->assertSame($lead['lead_code'], $this->json($dup)['details']['lead_code']);

        $other = $this->portal('post', 'leads', ['name' => 'Meena', 'mobile' => '9820011111', 'consent_confirmed' => true], $b['session']);
        $this->assertSame(409, $this->code($other), 'matched by mobile');
        $this->assertSame('lead_protected', $this->json($other)['code']);
        $this->assertSame('This contact is already registered with AICOUNTLY.', $this->json($other)['error']);
        $this->assertSame(3, $this->db()->table('engage_seller_lead_registrations')->countAllResults());

        $list = $this->json($this->portal('get', 'leads', [], $a['session']))['data'];
        $this->assertSame(1, $list['total']);
        $this->assertSame('m***@shah.example', $list['items'][0]['email_masked']);
        $this->assertSame('+91XXXXXX1111', $list['items'][0]['mobile_masked']);
        $this->assertSame('registered', $list['items'][0]['source']);
        $this->assertSame(0, $this->json($this->portal('get', 'leads', [], $b['session']))['data']['total']);

        // Protection expired: another seller may claim the (stale) lead.
        $this->db()->query("UPDATE engage_leads SET seller_protection_expires_at = NOW() - INTERVAL '1 day', created_at = NOW() - INTERVAL '120 days', updated_at = NOW() - INTERVAL '100 days'");
        $this->assertSame('expired', $this->json($this->portal('get', 'leads', [], $a['session']))['data']['items'][0]['status']);
        $claim = $this->portal('post', 'leads', ['name' => 'Meena', 'email' => 'MEENA@shah.example', 'consent_confirmed' => true], $b['session']);
        $this->assertSame(201, $this->code($claim));
        $this->assertSame($b['seller_id'], (int) $this->lead($lead['lead_code'])['seller_id']);
    }

    public function testExistingCustomerAndPipelineLeadsCannotBeRegistered(): void
    {
        $a = $this->activeSellerSession();
        $this->db()->table('engage_leads')->insert(['lead_code' => 'ENG-X-1', 'name' => 'Old customer', 'email' => 'cust@corp.example', 'stage' => 'converted', 'sales_status' => 'won', 'source_type' => 'manual']);
        $this->db()->table('engage_leads')->insert(['lead_code' => 'ENG-X-2', 'name' => 'Open lead', 'email' => 'open@corp.example', 'stage' => 'contacted', 'source_type' => 'manual']);
        foreach (['cust@corp.example', 'open@corp.example'] as $email) {
            $r = $this->portal('post', 'leads', ['name' => 'Someone', 'email' => $email, 'consent_confirmed' => true], $a['session']);
            $this->assertSame('lead_protected', $this->json($r)['code'], $email);
        }
    }

    // ------------------------------------------------------------------ Reach intake

    public function testReachRepeatPushNeverResetsAConvertedLeadAndDedupesCaseInsensitivelyAndByMobile(): void
    {
        $r = $this->reach(['email' => 'Meena@Shah.example', 'mobile' => '+91-98200-22222', 'utm_source' => 'google', 'landing_url' => 'https://www.aicountly.com/books',
            'reach_meta' => ['reach_lead_id' => 501]]);
        $this->assertSame(201, $this->code($r));
        $body = $this->json($r)['data'];
        $this->assertSame('created', $body['result']);
        $this->assertFalse($body['duplicate']);
        $code = $body['lead']['lead_code'];
        $this->assertSame(501, (int) $this->lead($code)['reach_lead_id']);

        $admin = $this->staff(['super_admin']);
        $id = (int) $this->lead($code)['id'];
        $this->asStaff($admin, 'post', "v1/leads/{$id}/assign", ['owner_id' => 7]);
        $this->asStaff($admin, 'post', "v1/leads/{$id}/move-stage", ['stage' => 'converted']);

        $again = $this->reach(['email' => 'MEENA@shah.EXAMPLE', 'utm_source' => 'facebook']);
        $this->assertSame(200, $this->code($again));
        $b2 = $this->json($again)['data'];
        $this->assertSame('updated', $b2['result']);
        $this->assertTrue($b2['duplicate']);
        $this->assertSame('email', $b2['matched_on']);
        $row = $this->lead($code);
        $this->assertSame('converted', $row['stage']);
        $this->assertSame('won', $row['sales_status']);
        $this->assertSame(7, (int) $row['owner_id']);
        $attr = json_decode($row['attribution'], true);
        $this->assertSame('google', $attr['first_touch']['utm']['utm_source']);
        $this->assertSame('facebook', $attr['last_touch']['utm']['utm_source']);

        $byMobile = $this->json($this->reach(['email' => 'different@x.example', 'mobile' => '9820022222']))['data'];
        $this->assertSame('mobile', $byMobile['matched_on']);
        $this->assertSame($code, $byMobile['lead']['lead_code']);
        $this->assertSame(1, $this->db()->table('engage_leads')->where('source_type', 'reach_campaign')->countAllResults());
    }

    public function testReachRefAttributesToSellerRespectingProtection(): void
    {
        $a = $this->activeSellerSession('a@seller.example');
        $b = $this->activeSellerSession('b@seller.example');

        $r = $this->json($this->reach(['email' => 'ref@cust.example', 'ref' => strtolower($a['seller_code'])]))['data'];
        $this->assertSame('attributed', $r['seller_attribution']['status']);
        $row = $this->lead($r['lead']['lead_code']);
        $this->assertSame($a['seller_id'], (int) $row['seller_id']);
        $this->assertSame('referral', $row['seller_attribution']);
        $this->assertSame('referral_link', $this->json($this->portal('get', 'leads', [], $a['session']))['data']['items'][0]['source']);

        $r2 = $this->json($this->reach(['email' => 'ref@cust.example', 'reach_meta' => ['ref' => $b['seller_code']]]))['data'];
        $this->assertSame('ignored', $r2['seller_attribution']['status']);
        $this->assertSame($a['seller_id'], (int) $this->lead($r['lead']['lead_code'])['seller_id']);

        $r3 = $this->json($this->reach(['email' => 'nobody@cust.example', 'ref' => 'SLR-999999']))['data'];
        $this->assertSame('ignored', $r3['seller_attribution']['status']);
        $this->assertNull($this->lead($r3['lead']['lead_code'])['seller_id']);
    }

    // ------------------------------------------------------------------ commissions

    private function plan(string $token, array $body): array
    {
        return $this->json($this->asStaff($token, 'post', 'v1/commission-plans', $body));
    }

    public function testNoPlanMeansNoAccrualAndPublishedPlanAccrues(): void
    {
        $a = $this->activeSellerSession();
        $code = $this->json($this->portal('post', 'leads', ['name' => 'Cust', 'email' => 'cust@x.example', 'interest' => 'books', 'consent_confirmed' => true], $a['session']))['data']['lead']['lead_code'];
        $leadId = (int) $this->lead($code)['id'];
        $sales = $this->staff(['sales_manager'], 'sales@aicountly.org');

        $this->asStaff($sales, 'post', "v1/leads/{$leadId}/move-stage", ['stage' => 'converted']);
        $pay = $this->asStaff($sales, 'post', "v1/leads/{$leadId}/payments", ['amount' => '12000.00', 'payment_ref' => 'INV-1']);
        $this->assertSame(201, $this->code($pay));
        $this->assertSame(0, $this->json($pay)['data']['accrual']['accrued']);
        $this->assertSame(0, $this->db()->table('engage_commission_entries')->countAllResults());
        $c = $this->json($this->portal('get', 'commissions', [], $a['session']))['data'];
        $this->assertFalse($c['plan']['published']);
        $this->assertSame('No commission plan published yet.', $c['message']);

        // Plans: member / seller_manager cannot write; sales_manager can.
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['seller_manager'], 'sm@aicountly.org'), 'post', 'v1/commission-plans', ['plan_code' => 'x'])));
        $bad = $this->plan($sales, ['plan_code' => 'std', 'name' => 'Standard', 'rules' => ['type' => 'percent_of_first_payment', 'percent' => '150']]);
        $this->assertArrayHasKey('rules', $bad['details']);
        $p = $this->plan($sales, ['plan_code' => 'std', 'name' => 'Standard', 'rules' => ['type' => 'percent_of_first_payment', 'percent' => '10'], 'product_scope' => ['books'], 'hold_days' => 15])['data'];
        $this->assertSame('draft', $p['status']);
        $pub = $this->json($this->asStaff($sales, 'post', "v1/commission-plans/{$p['id']}/publish"))['data'];
        $this->assertSame('active', $pub['status']);
        // The earlier payment was already evaluated (no plan then): not accrued retroactively.
        $this->assertSame(0, $this->db()->table('engage_commission_entries')->countAllResults());

        $pay2 = $this->json($this->asStaff($sales, 'post', "v1/leads/{$leadId}/payments", ['amount' => '12000.00', 'payment_ref' => 'INV-2']))['data'];
        $this->assertNull($pay2['entry'], 'second payment: percent_of_first_payment pays nothing');

        // New converted lead: first payment accrues 10%.
        $code2 = $this->json($this->portal('post', 'leads', ['name' => 'Cust2', 'email' => 'cust2@x.example', 'interest' => 'books', 'consent_confirmed' => true], $a['session']))['data']['lead']['lead_code'];
        $lead2 = (int) $this->lead($code2)['id'];
        $p1 = $this->json($this->asStaff($sales, 'post', "v1/leads/{$lead2}/payments", ['amount' => '9999.99', 'payment_ref' => 'INV-9']))['data'];
        $this->assertNull($p1['entry'], 'lead not converted yet');
        $this->asStaff($sales, 'post', "v1/leads/{$lead2}/move-stage", ['stage' => 'converted']);
        $entry = $this->db()->table('engage_commission_entries')->where('lead_id', $lead2)->get()->getRowArray();
        $this->assertSame('1000.00', $entry['amount']);
        $this->assertSame('accrued', $entry['status']);
        $this->assertSame($p['id'], (int) $entry['plan_id']);

        $dupPay = $this->asStaff($sales, 'post', "v1/leads/{$lead2}/payments", ['amount' => '9999.99', 'payment_ref' => 'INV-9']);
        $this->assertSame(200, $this->code($dupPay), 'natural key: same payment again');
        $this->assertTrue($this->json($dupPay)['data']['duplicate']);
        $this->assertSame(409, $this->code($this->asStaff($sales, 'post', "v1/leads/{$lead2}/payments", ['amount' => '1.00', 'payment_ref' => 'INV-9'])));

        $sc = $this->json($this->portal('get', 'commissions', [], $a['session']))['data'];
        $this->assertTrue($sc['plan']['published']);
        $this->assertSame('1000.00', $sc['totals']['accrued']);
        $this->assertSame('10% of first payment', $sc['items'][0]['rule_label']);
        $this->assertSame('AICOUNTLY Books', $sc['items'][0]['product']);
        $this->assertSame('CE-' . $entry['id'], $sc['items'][0]['id']);

        // Hold release (hourly command).
        $this->assertSame(0, Services::commissions()->releaseDue());
        $this->db()->query("UPDATE engage_commission_entries SET hold_until = NOW() - INTERVAL '1 minute'");
        $this->assertSame(1, Services::commissions()->releaseDue());
        $this->assertSame('approved', $this->db()->table('engage_commission_entries')->get()->getRow()->status);

        // Reverse needs a reason; a refund reverses too.
        $this->assertSame(422, $this->code($this->asStaff($sales, 'post', "v1/commission-entries/{$entry['id']}/reverse", ['reason' => ''])));
        $payId = (int) $entry['payment_id'];
        $ref = $this->json($this->asStaff($sales, 'post', "v1/lead-payments/{$payId}/refund", ['reason' => 'Customer refunded']))['data'];
        $this->assertSame(1, $ref['entries_reversed']);
        $this->assertSame('reversed', $this->db()->table('engage_commission_entries')->get()->getRow()->status);
        $this->db()->query("UPDATE engage_commission_entries SET status = 'paid'");
        $this->assertSame(409, $this->code($this->asStaff($sales, 'post', "v1/commission-entries/{$entry['id']}/reverse", ['reason' => 'too late'])));
    }

    public function testPlanVersioningAndOverlap(): void
    {
        $sales = $this->staff(['super_admin']);
        $p = $this->plan($sales, ['plan_code' => 'std', 'name' => 'Standard', 'rules' => ['type' => 'flat_per_conversion', 'amount' => '2500'], 'product_scope' => ['*']])['data'];
        $this->asStaff($sales, 'post', "v1/commission-plans/{$p['id']}/publish");
        $o = $this->plan($sales, ['plan_code' => 'books', 'name' => 'Books', 'rules' => ['type' => 'percent_recurring_months', 'percent' => '5', 'months' => 12], 'product_scope' => ['books']])['data'];
        $this->assertSame(409, $this->code($this->asStaff($sales, 'post', "v1/commission-plans/{$o['id']}/publish")), 'overlapping scope');

        $this->assertSame(409, $this->code($this->asStaff($sales, 'put', "v1/commission-plans/{$p['id']}", ['name' => 'Edited'])), 'active plans are not edited in place');
        $v2 = $this->json($this->asStaff($sales, 'post', "v1/commission-plans/{$p['id']}/new-version"))['data'];
        $this->assertSame(2, $v2['version']);
        $this->asStaff($sales, 'put', "v1/commission-plans/{$v2['id']}", ['rules' => ['type' => 'slab', 'slabs' => [['from' => '0', 'to' => '50000', 'percent' => '8'], ['from' => '50000', 'to' => null, 'amount' => '6000']]]]);
        $this->asStaff($sales, 'post', "v1/commission-plans/{$v2['id']}/publish");
        $plans = $this->json($this->asStaff($sales, 'get', 'v1/commission-plans'))['data']['items'];
        $byId = array_column($plans, 'status', 'id');
        $this->assertSame('retired', $byId[$p['id']]);
        $this->assertSame('active', $byId[$v2['id']]);
    }

    // ------------------------------------------------------------------ Reach outcomes

    public function testOutcomeEventsAreQueuedAndDelivered(): void
    {
        $a = $this->activeSellerSession();
        $code = $this->json($this->reach(['email' => 'out@cust.example', 'ref' => $a['seller_code'], 'reach_meta' => ['reach_lead_id' => 77]]))['data']['lead']['lead_code'];
        $id = (int) $this->lead($code)['id'];
        $admin = $this->staff(['super_admin']);
        $this->asStaff($admin, 'post', "v1/leads/{$id}/move-stage", ['stage' => 'demo_required']);
        $this->asStaff($admin, 'post', "v1/leads/{$id}/move-stage", ['stage' => 'contacted']); // not an outcome stage
        $this->asStaff($admin, 'post', "v1/leads/{$id}/move-stage", ['stage' => 'converted']);
        $this->asStaff($admin, 'post', "v1/leads/{$id}/payments", ['amount' => '5000.50', 'payment_ref' => 'RZP-1']);
        // A non-Reach lead never produces an event.
        $own = $this->json($this->portal('post', 'leads', ['name' => 'Own', 'email' => 'own@x.example', 'consent_confirmed' => true], $a['session']))['data']['lead']['lead_code'];
        $this->asStaff($admin, 'post', 'v1/leads/' . $this->lead($own)['id'] . '/move-stage', ['stage' => 'converted']);

        $rows = $this->db()->table('engage_reach_outcome_outbox')->orderBy('id')->get()->getResultArray();
        $stages = array_column($rows, 'stage');
        $this->assertSame(['demo', 'converted', 'payment_confirmed'], array_values(array_diff($stages, ['qualified'])), 'contacted is not an outcome stage; the own lead sends nothing');
        $events = array_map(static fn ($r) => json_decode($r['event'], true), $rows);
        $pay = end($events);
        $this->assertSame('5000.50', $pay['value_inr']);
        $this->assertSame(77, $pay['reach_lead_id']);
        $this->assertSame(hash('sha256', 'out@cust.example'), $pay['email_sha256']);
        $this->assertSame($a['seller_code'], $pay['seller_code']);
        $this->assertSame('diwali-2026', $pay['campaign_code']);

        $out = Services::reachOutcomes()->deliverDue();
        $this->assertSame(count($rows), $out['delivered']);
        $this->assertSame('https://reach.test/api/v1/inbound/engage/lead-outcomes', $this->reachCalls[0]['url']);
        $this->assertContains('X-Engage-Token: ' . str_repeat('t', 40), $this->reachCalls[0]['headers']);
        $this->assertSame(array_column($events, 'event_id'), array_column($this->reachCalls[0]['body']['events'], 'event_id'));

        // Failure → retry with back-off; per-event rejection → kept, not retried.
        $this->asStaff($admin, 'post', "v1/leads/{$id}/payments", ['amount' => '10.00', 'payment_ref' => 'RZP-2']);
        $this->reachAnswer = ['status' => 503, 'body' => 'down'];
        $this->assertSame(1, Services::reachOutcomes()->deliverDue()['retry']);
        $row = $this->db()->table('engage_reach_outcome_outbox')->where('event_id', 'eng-pay-' . $this->db()->table('engage_lead_payments')->where('payment_ref', 'RZP-2')->get()->getRow()->id)->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->db()->query('UPDATE engage_reach_outcome_outbox SET next_attempt_at = NOW()');
        $this->reachAnswer = ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => ['results' => [['event_id' => $row['event_id'], 'status' => 'rejected', 'error' => 'unknown lead']]]])];
        $this->assertSame(1, Services::reachOutcomes()->deliverDue()['rejected']);
    }
}

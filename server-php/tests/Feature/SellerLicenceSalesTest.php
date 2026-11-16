<?php

namespace Tests\Feature;

use App\Libraries\AicS2s\AicS2sSignature;
use App\Libraries\Seller\SellerCrypto;
use App\Services\SellerLicences\SellerLicenceOrderService;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\SellerTestCase;

/**
 * Sellers buy licences and apply them to customers (docs/SELLING_LICENCES.md §7): the seller catalogue, an order at a
 * frozen price, settlement (staff confirming an offline payment, or Finance's signed payment event), stock, applying
 * a licence (new, then renewal), commission on the sale, lead protection, plan conflicts, withdrawal and cancellation.
 *
 * @internal
 */
final class SellerLicenceSalesTest extends SellerTestCase
{
    private const FIN_KEY_ID = 'finance-events-2026-11';
    private const FIN_SECRET = 'finance-events-secret-0123456789abcdefghijklm';

    private string $sales;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(array_merge($_ENV, $_SERVER, getenv())) as $k) {
            if (is_string($k) && (str_starts_with($k, 'ENGAGE_INBOUND_FINANCE_') || in_array($k, ['ENGAGE_S2S_ENVIRONMENT', 'ENGAGE_SELLER_ORDER_PAYMENT_LINKS', 'ENGAGE_INBOUND_FINANCE_KEY'], true))) {
                $this->setEnv($k, null);
            }
        }
        $this->sales = $this->staff(['sales_manager'], 'sales@aicountly.org');
        $this->catalogueFixtures();
    }

    // ------------------------------------------------------------------ fixtures

    private function catalogueFixtures(): void
    {
        foreach ([['standard', 'paid'], ['basic', 'complimentary']] as [$code, $kind]) {
            $this->assertSame(201, $this->code($this->asStaff($this->sales, 'post', 'v1/licence-types', ['code' => $code, 'label' => ucfirst($code), 'lifecycle_kind' => $kind])));
        }
        $this->offer('books-basic', ['label' => 'Basic', 'price_paise' => 0, 'sac' => null, 'term_months' => 0, 'licence_type_code' => 'basic', 'is_default' => true,
            'entitlements' => [['code' => 'max_companies', 'label' => 'Companies', 'value' => 1, 'unit' => 'count']]]);
        $this->offer('books-pro', ['label' => 'Books Pro']);
        $this->offer('books-lite', ['label' => 'Books Lite', 'price_paise' => 590000]);
        $plan = $this->json($this->asStaff($this->sales, 'post', 'v1/commission-plans', ['plan_code' => 'resale', 'name' => 'Resale',
            'rules' => ['type' => 'percent_of_first_payment', 'percent' => '20'], 'product_scope' => ['books'], 'hold_days' => 15]))['data'];
        $this->assertSame(200, $this->code($this->asStaff($this->staff(['super_admin'], 'boss@aicountly.org'), 'post', "v1/commission-plans/{$plan['id']}/publish")));
    }

    private function offer(string $code, array $extra = []): int
    {
        $r = $this->asStaff($this->sales, 'post', 'v1/offers', array_merge([
            'offer_code' => $code, 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => $code,
            'price_paise' => 1180000, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331', 'term_months' => 12, 'term_days' => 0,
        ], $extra));
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $id = (int) $this->json($r)['data']['id'];
        $p = $this->asStaff($this->sales, 'post', "v1/offers/{$id}/publish");
        $this->assertSame(200, $this->code($p), (string) $p->response()->getBody());

        return $id;
    }

    /** An active seller with verified KYC (GST-registered in Maharashtra). @return array<string,mixed> */
    private function verifiedSeller(string $email = 'ravi@example.com', string $org = 'Ravi Associates'): array
    {
        $s = $this->activeSellerSession($email);
        $this->db()->table('engage_seller_kyc')->insert([
            'seller_id' => $s['seller_id'], 'legal_name' => $org, 'entity_type' => 'proprietorship', 'gstin_enc' => (new SellerCrypto())->encrypt('27ABCPK1234F1Z5'),
            'gstin_last4' => 'F1Z5', 'gst_state_code' => '27', 'address_line1' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001',
            'status' => 'kyc_verified',
        ]);
        $this->db()->table('engage_sellers')->where('id', $s['seller_id'])->update(['status' => 'active', 'kyc_status' => 'kyc_verified', 'organization' => $org]);

        return $s;
    }

    /** A signed-up customer holding the Basic of books. */
    private function customer(string $uuid = '10042', string $email = 'asha@example.com', string $name = 'Asha Verma'): void
    {
        $r = Services::provisioning()->provision(['holder_uuid' => $uuid, 'email' => $email, 'name' => $name, 'signed_up_at' => gmdate('c'), 'request_id' => 'req-' . $uuid]);
        $this->assertSame('active', $this->db()->table('engage_licences')->where('holder_uuid', $uuid)->where('is_basic', true)->get()->getRow()->status, json_encode($r));
    }

    private function order(string $session, array $lines, ?string $key = null): TestResponse
    {
        return $this->portal('post', 'licence-orders', ['lines' => $lines], $session, $key !== null ? ['Idempotency-Key' => $key] : []);
    }

    private function paidOrder(string $session, int $qty = 2, string $code = 'books-pro', string $utr = 'UTR0001'): array
    {
        $o = $this->json($this->order($session, [['offer_code' => $code, 'quantity' => $qty]]))['data'];
        $r = $this->asStaff($this->sales, 'post', "v1/seller-licence-orders/{$o['order_ref']}/confirm-payment",
            ['payment_ref' => $utr, 'amount' => number_format($o['total_paise'] / 100, 2, '.', ''), 'invoice_no' => 'INV/26-27/0' . $qty . substr($utr, -2)]);
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());

        return $this->json($r)['data'];
    }

    private function units(string $session, string $status = 'available'): array
    {
        return $this->json($this->portal('get', 'licence-stock', ['status' => $status], $session))['data']['items'];
    }

    private function applyUnit(string $session, string $unitRef, string $email, ?string $key = null): TestResponse
    {
        return $this->portal('post', "licence-stock/{$unitRef}/apply", ['customer_email' => $email, 'consent_confirmed' => true], $session,
            $key !== null ? ['Idempotency-Key' => $key] : []);
    }

    // ------------------------------------------------------------------ tests

    public function testCatalogueOrderAndOfflinePaymentCreateStock(): void
    {
        $this->db()->table('engage_settings')->where('key', 'seller_order_payment_instructions')->update(['value_json' => json_encode('HDFC 50200012345678, IFSC HDFC0001234')]);
        $s = $this->verifiedSeller();

        $cat = $this->json($this->portal('get', 'licence-catalogue', [], $s['session']))['data'];
        $this->assertTrue($cat['can_buy']);
        $this->assertSame('offline', $cat['payment']['mode']);
        $offers = $cat['products'][0]['offers'];
        $this->assertSame(['books-lite', 'books-pro'], array_column($offers, 'offer_code'), 'priced offers only — never the Basic');
        $this->assertSame(['taxable_paise' => 1000000, 'gst_paise' => 180000, 'total_paise' => 1180000],
            array_intersect_key($offers[1]['price'], array_flip(['taxable_paise', 'gst_paise', 'total_paise'])));
        $this->assertSame('20% of first payment', $cat['products'][0]['commission']['rule']);

        $r = $this->order($s['session'], [['offer_code' => 'books-pro', 'quantity' => 3]], 'order-key-000001');
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $o = $this->json($r)['data'];
        $this->assertMatchesRegularExpression('/\ASLO-\d{6}\z/', $o['order_ref']);
        $this->assertSame('awaiting_payment', $o['status']);
        $this->assertSame([3540000, 3000000, 540000], [$o['total_paise'], $o['taxable_paise'], $o['gst_paise']]);
        $this->assertSame(['legal_name' => 'Ravi Associates', 'gstin_masked' => '27XXXXXXXXXF1Z5', 'state_code' => '27'], $o['buyer']);
        $this->assertSame('HDFC 50200012345678, IFSC HDFC0001234', $o['payment']['instructions']);
        $this->assertSame($o['order_ref'], $this->json($this->order($s['session'], [['offer_code' => 'books-pro', 'quantity' => 3]], 'order-key-000001'))['data']['order_ref'], 'a retry is the same order');
        $this->assertSame(1, $this->db()->table('engage_seller_licence_orders')->countAllResults());

        // Validation and what sellers may not buy.
        $bad = $this->order($s['session'], [['offer_code' => 'books-pro', 'quantity' => 1], ['offer_code' => 'books-pro', 'quantity' => 0]]);
        $this->assertSame(422, $this->code($bad));
        $this->assertEqualsCanonicalizing(['lines.1.offer_code', 'lines.1.quantity'], array_keys($this->json($bad)['details']));
        $basic = $this->order($s['session'], [['offer_code' => 'books-basic', 'quantity' => 1]]);
        $this->assertSame(422, $this->code($basic));
        $this->assertSame(['lines.0.offer_code'], array_keys($this->json($basic)['details']), 'the Basic is never sold to sellers');

        // Staff confirm the bank transfer: the exact amount, with Finance's invoice number.
        $url = "v1/seller-licence-orders/{$o['order_ref']}/confirm-payment";
        $this->assertSame(422, $this->code($this->asStaff($this->sales, 'post', $url, ['payment_ref' => 'UTR123', 'amount' => '35400.00'])), 'invoice number required');
        $mm = $this->asStaff($this->sales, 'post', $url, ['payment_ref' => 'UTR123', 'amount' => '35000.00', 'invoice_no' => 'INV/1']);
        $this->assertSame(409, $this->code($mm));
        $this->assertSame('amount_mismatch', $this->json($mm)['code']);
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['seller_manager'], 'sm@aicountly.org'), 'post', $url, ['payment_ref' => 'UTR123', 'amount' => '35400.00', 'invoice_no' => 'INV/1'])));
        $ok = $this->asStaff($this->sales, 'post', $url, ['payment_ref' => 'UTR123', 'amount' => '35400.00', 'invoice_no' => 'INV/1', 'invoice_date' => '2026-11-15']);
        $this->assertSame(200, $this->code($ok), (string) $ok->response()->getBody());
        $this->assertSame('paid', $this->json($ok)['data']['status']);
        $this->assertSame(200, $this->code($this->asStaff($this->sales, 'post', $url, ['payment_ref' => 'UTR123', 'amount' => '35400.00', 'invoice_no' => 'INV/1'])), 'the same payment again is harmless');

        $units = $this->units($s['session']);
        $this->assertCount(3, $units);
        $rows = $this->db()->table('engage_seller_licence_units')->get()->getResultArray();
        $this->assertSame(3000000, array_sum(array_column($rows, 'taxable_paise')), 'unit shares add up to the line');
        $this->assertSame(540000, array_sum(array_column($rows, 'gst_paise')));
    }

    public function testOnlyAnActiveVerifiedSellerCanBuy(): void
    {
        $s = $this->activeSellerSession();
        $r = $this->order($s['session'], [['offer_code' => 'books-pro', 'quantity' => 1]]);
        $this->assertSame(403, $this->code($r));
        $this->assertSame('seller_not_active', $this->json($r)['code']);
        $this->assertFalse($this->json($this->portal('get', 'licence-catalogue', [], $s['session']))['data']['can_buy']);
    }

    public function testApplyingAUnitIssuesTheLicenceAndAccruesCommissionThenRenews(): void
    {
        $s = $this->verifiedSeller();
        $this->customer();
        $this->paidOrder($s['session'], 2);
        [$u1, $u2] = array_column($this->units($s['session']), 'unit_ref');

        $look = $this->json($this->portal('post', 'customers/lookup', ['email' => 'ASHA@example.com'], $s['session']))['data'];
        $this->assertTrue($look['found'] && $look['eligible']);
        $this->assertSame('A*** V***', $look['customer']['name_masked']);
        $this->assertFalse($this->json($this->portal('post', 'customers/lookup', ['email' => 'nobody@example.com'], $s['session']))['data']['found']);

        $this->assertSame(422, $this->code($this->portal('post', "licence-stock/{$u1}/apply", ['customer_email' => 'asha@example.com'], $s['session'])), 'consent required');
        $nf = $this->applyUnit($s['session'], $u1, 'nobody@example.com');
        $this->assertSame(404, $this->code($nf));
        $this->assertSame('customer_not_found', $this->json($nf)['code']);

        $r = $this->applyUnit($s['session'], $u1, 'asha@example.com', 'apply-key-00000001');
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $d = $this->json($r)['data'];
        $this->assertSame('new', $d['kind']);
        $this->assertSame('applied', $d['unit']['status']);
        $lic = $this->db()->table('engage_licences')->where('licence_ref', $d['licence']['licence_ref'])->get()->getRowArray();
        $this->assertSame(['seller', '10042', $u1, $s['seller_id']], [$lic['source'], $lic['holder_uuid'], $lic['order_ref'], (int) $lic['seller_id']]);
        $this->assertNull($lic['account_id']);
        $this->assertSame('superseded', $this->db()->table('engage_licences')->where('holder_uuid', '10042')->where('is_basic', true)->get()->getRow()->status, 'the paid plan replaces Basic');
        $this->assertGreaterThan(0, $this->db()->table('engage_entitlement_outbox')->where('holder_uuid', '10042')->countAllResults(), 'Manage is told');
        $ev = $this->db()->table('engage_licence_events')->where('licence_ref', $lic['licence_ref'])->where('type', 'issued')->get()->getRowArray();
        $this->assertSame('seller', $ev['actor_kind']);
        $this->assertStringContainsString('Ravi Associates', $ev['actor_display']);
        $this->assertSame('Ravi Associates, Aicountly seller ' . $s['seller_code'],
            \App\Services\Licensing\LicenceService::contractEvent($ev)['actor']['display']);
        $view = \App\Services\Licensing\LicenceService::contractView(Services::licences()->findByRef($lic['licence_ref']));
        $this->assertSame(['kind' => 'seller', 'seller_code' => $s['seller_code'], 'name' => 'Ravi Associates'], $view['sold_by']);
        $this->assertContains(['label' => 'Sold by', 'value' => 'Ravi Associates (' . $s['seller_code'] . ')'], $view['attributes']);

        // The customer is the seller's converted lead; the sale is a payment through the seller; commission accrued.
        $lead = $this->db()->table('engage_leads')->where('email', 'asha@example.com')->get()->getRowArray();
        $this->assertSame(['converted', 'won', 'licence_sale', $s['seller_id']], [$lead['stage'], $lead['sales_status'], $lead['seller_attribution'], (int) $lead['seller_id']]);
        $pay = $this->db()->table('engage_lead_payments')->where('payment_ref', $u1)->get()->getRowArray();
        $this->assertSame(['seller_licence', 1, 1000000, $s['seller_id']], [$pay['source'], (int) $pay['commission_sequence'], (int) $pay['basis_paise'], (int) $pay['seller_id']]);
        $entry = $this->db()->table('engage_commission_entries')->where('payment_id', $pay['id'])->get()->getRowArray();
        $this->assertSame(['accrued', '2000.00', $s['seller_id']], [$entry['status'], $entry['amount'], (int) $entry['seller_id']]);
        $this->assertStringContainsString('2000.00', (string) $d['commission']['note']);

        // A retry of the same apply is the same answer; the same unit cannot be applied twice.
        $this->assertSame($d['licence']['licence_ref'], $this->json($this->applyUnit($s['session'], $u1, 'asha@example.com', 'apply-key-00000001'))['data']['licence']['licence_ref']);
        $this->assertSame(409, $this->code($this->applyUnit($s['session'], $u1, 'asha@example.com')));

        // The second unit of the same plan renews it by a term; a renewal is payment #2, which this plan does not pay.
        $endsBefore = strtotime((string) $lic['ends_at']);
        $r2 = $this->applyUnit($s['session'], $u2, 'asha@example.com');
        $this->assertSame(200, $this->code($r2), (string) $r2->response()->getBody());
        $this->assertSame('renewal', $this->json($r2)['data']['kind']);
        $after = $this->db()->table('engage_licences')->where('licence_ref', $lic['licence_ref'])->get()->getRowArray();
        $this->assertGreaterThanOrEqual(365 * 86400, strtotime((string) $after['ends_at']) - $endsBefore);
        $this->assertSame(1, $this->db()->table('engage_licences')->where('holder_uuid', '10042')->where('is_basic', false)->countAllResults());
        $pay2 = $this->db()->table('engage_lead_payments')->where('payment_ref', $u2)->get()->getRowArray();
        $this->assertSame(2, (int) $pay2['commission_sequence']);
        $this->assertSame(0, $this->db()->table('engage_commission_entries')->where('payment_id', $pay2['id'])->countAllResults());

        $cl = $this->json($this->portal('get', 'customer-licences', [], $s['session']))['data'];
        $this->assertSame(1, $cl['total']);
        $this->assertSame(['Books Pro', 'active'], [$cl['items'][0]['plan'], $cl['items'][0]['status']]);
    }

    public function testAnotherSellersProtectedCustomerAndAPlanConflictAreRefused(): void
    {
        $a = $this->verifiedSeller('ravi@example.com');
        $b = $this->verifiedSeller('meena@example.com', 'Meena Traders');
        $this->customer('10042', 'asha@example.com');
        $this->customer('10043', 'karan@example.com', 'Karan Shah');
        // Meena registered Karan as her lead: protected for her.
        $this->assertSame(201, $this->code($this->portal('post', 'leads', ['name' => 'Karan Shah', 'email' => 'karan@example.com', 'consent_confirmed' => true], $b['session'])));
        $this->paidOrder($a['session'], 1, 'books-pro', 'UTR0101');
        $unit = $this->units($a['session'])[0]['unit_ref'];

        $look = $this->json($this->portal('post', 'customers/lookup', ['email' => 'karan@example.com'], $a['session']))['data'];
        $this->assertFalse($look['eligible']);
        $r = $this->applyUnit($a['session'], $unit, 'karan@example.com');
        $this->assertSame(409, $this->code($r));
        $this->assertSame('lead_protected', $this->json($r)['code']);
        $this->assertStringNotContainsString('Meena', (string) $r->response()->getBody());

        // Asha holds Books Lite (another plan of books) through Ravi: his Books Pro licence is refused, not stacked.
        $this->paidOrder($a['session'], 1, 'books-lite', 'UTR0202');
        $lite = array_values(array_filter($this->units($a['session']), static fn (array $u): bool => $u['offer_code'] === 'books-lite'))[0]['unit_ref'];
        $this->assertSame(200, $this->code($this->applyUnit($a['session'], $lite, 'asha@example.com')));
        $c = $this->applyUnit($a['session'], $unit, 'asha@example.com');
        $this->assertSame(409, $this->code($c));
        $this->assertSame('plan_conflict', $this->json($c)['code']);
        $this->assertStringContainsString('books-lite', (string) $c->response()->getBody());
        $this->assertSame('available', $this->db()->table('engage_seller_licence_units')->where('unit_ref', $unit)->get()->getRow()->status, 'nothing changed');
        $this->assertSame(1, $this->db()->table('engage_lead_payments')->where('source', 'seller_licence')->countAllResults(), 'no sale recorded for the refused one');

        // And Meena cannot sell to Ravi's customer while it is protected for him.
        $this->paidOrder($b['session'], 1, 'books-lite', 'UTR0303');
        $m = $this->applyUnit($b['session'], $this->units($b['session'])[0]['unit_ref'], 'asha@example.com');
        $this->assertSame(409, $this->code($m));
        $this->assertSame('lead_protected', $this->json($m)['code']);
    }

    public function testFinanceSignedPaymentEventSettlesTheOrder(): void
    {
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'sandbox');
        $this->setEnv('ENGAGE_INBOUND_FINANCE_KEY_IDS', self::FIN_KEY_ID);
        $this->setEnv('ENGAGE_INBOUND_FINANCE_SECRET_FINANCE_EVENTS_2026_11', self::FIN_SECRET);
        $this->setEnv('ENGAGE_INBOUND_FINANCE_SCOPE_FINANCE_EVENTS_2026_11', 'issue');
        $s = $this->verifiedSeller();
        $o = $this->json($this->order($s['session'], [['offer_code' => 'books-lite', 'quantity' => 2]]))['data'];

        $event = static fn (int $amount, string $id = 'evt-1') => json_encode(['event_id' => $id, 'event_type' => 'payment.succeeded', 'lead_code' => $o['order_ref'],
            'payment_ref' => 'pay_RZP123', 'amount_paise' => $amount, 'paid_at' => gmdate('c')]);
        $send = function (string $body): TestResponse {
            $h = AicS2sSignature::headers('finance', self::FIN_KEY_ID, self::FIN_SECRET, 'sandbox', 'POST', '/v1/inbound/finance/payment-events', '', $body, 'service');
            $this->headers = [];

            return $this->withHeaders($h + ['Content-Type' => 'application/json'])->withBody($body)->call('post', 'v1/inbound/finance/payment-events');
        };

        $mm = $send($event(100));
        $this->assertSame(409, $this->code($mm));
        $this->assertSame('amount_mismatch', $this->json($mm)['code']);
        $r = $send($event($o['total_paise']));
        $this->assertSame(200, $this->code($r), (string) $r->response()->getBody());
        $this->assertSame('evt-1', $this->json($r)['data']['event_id'], 'Finance counts the event delivered');
        $this->assertSame('paid', $this->json($r)['data']['status']);
        $this->assertCount(2, $this->units($s['session']));
        $again = $send($event($o['total_paise'], 'evt-2'));
        $this->assertSame(200, $this->code($again));
        $this->assertTrue($this->json($again)['data']['duplicate']);
        $this->assertCount(2, $this->units($s['session']), 'a redelivered payment creates nothing');

        // Unsigned and without the legacy key: refused.
        $this->headers = [];
        $this->assertSame(503, $this->code($this->withHeaders(['Content-Type' => 'application/json'])->withBody($event($o['total_paise']))->call('post', 'v1/inbound/finance/payment-events')));
        // An event Engage does not act on is acknowledged without event_id.
        $lead = $send(json_encode(['event_id' => 'evt-9', 'event_type' => 'payment.succeeded', 'lead_code' => 'ENG-20261115-0001', 'payment_ref' => 'x', 'amount_paise' => 100]));
        $this->assertSame(200, $this->code($lead));
        $this->assertArrayNotHasKey('event_id', $this->json($lead)['data']);
    }

    /**
     * The payment-link request for an order is the one Finance accepts (finance-aicountly PaymentLinkInput::
     * validateLicences, migration 009): purpose licences; a line per offer — unit price × quantity on its own basis,
     * GST split by the seller's state as Finance's GstCalculator does; the totals; the seller's business as the buyer.
     */
    public function testTheFinanceRequestIsTheOneFinanceAccepts(): void
    {
        $this->setEnv('ENGAGE_GST_STATE_CODE', '27');
        $this->offer('books-plus', ['label' => 'Books Plus', 'price_paise' => 100000, 'price_basis' => 'exclusive']);
        $s = $this->verifiedSeller();
        $o = $this->json($this->order($s['session'], [['offer_code' => 'books-pro', 'quantity' => 2], ['offer_code' => 'books-plus', 'quantity' => 1]]))['data'];
        $row = $this->db()->table('engage_seller_licence_orders')->where('order_ref', $o['order_ref'])->get()->getRowArray();
        $lines = $this->db()->table('engage_seller_licence_order_lines')->where('order_id', $row['id'])->orderBy('id')->get()->getResultArray();
        $req = (new SellerLicenceOrderService())->financeRequest($row, $lines);

        $this->assertSame(['licences', $o['order_ref'], 'Aicountly licences — order ' . $o['order_ref'], '27ABCPK1234F1Z5', 2478000],
            [$req['purpose'], $req['source_ref'], $req['description'], $req['buyer_gstin'], $req['amount_paise']]);
        $this->assertMatchesRegularExpression('/\ASLR-\d{6}\z/', $req['seller_code']);
        $this->assertSame(['name' => 'Ravi Associates', 'email' => 'ravi@example.com', 'mobile' => '+919876543210', 'state_code' => '27',
            'address' => '12 MG Road, Pune, Maharashtra', 'pincode' => '411001'], $req['payer'], 'the seller\'s business, from KYC');
        $pick = static fn (array $l): array => array_intersect_key($l, array_flip(['offer_code', 'quantity', 'unit_price_paise', 'amount_paise', 'price_basis', 'gst_rate', 'sac',
            'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise']));
        $this->assertSame([
            ['offer_code' => 'books-pro', 'quantity' => 2, 'unit_price_paise' => 1180000, 'amount_paise' => 2360000, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331',
                'taxable_paise' => 2000000, 'cgst_paise' => 180000, 'sgst_paise' => 180000, 'igst_paise' => 0],
            ['offer_code' => 'books-plus', 'quantity' => 1, 'unit_price_paise' => 100000, 'amount_paise' => 100000, 'price_basis' => 'exclusive', 'gst_rate' => '18', 'sac' => '997331',
                'taxable_paise' => 100000, 'cgst_paise' => 9000, 'sgst_paise' => 9000, 'igst_paise' => 0],
        ], array_map($pick, $req['lines']), 'each line on its own basis; CGST + SGST for a Maharashtra seller');
        $this->assertSame(['taxable_paise' => 2100000, 'cgst_paise' => 189000, 'sgst_paise' => 189000, 'igst_paise' => 0], $req['fee'], 'the totals');

        // Another state: IGST, line by line.
        $this->setEnv('ENGAGE_GST_STATE_CODE', '29');
        $igst = (new SellerLicenceOrderService())->financeRequest($row, $lines);
        $this->assertSame([[0, 0, 360000], [0, 0, 18000]], array_map(static fn (array $l): array => [$l['cgst_paise'], $l['sgst_paise'], $l['igst_paise']], $igst['lines']));
    }

    /** A link Finance could not raise: the seller is told why in plain words and can pay by bank transfer meanwhile. */
    public function testAFailedLinkSaysWhyAndOffersBankTransfer(): void
    {
        $this->setEnv('ENGAGE_SELLER_ORDER_PAYMENT_LINKS', '1');
        $this->setEnv('ENGAGE_GST_STATE_CODE', '27');
        $this->setEnv('FINANCE_API_BASE_URL', 'http://127.0.0.1:9/api');
        $this->setEnv('ENGAGE_OUTBOUND_FINANCE_S2S_KEY_ID', 'engage-issue-2026-11');
        $this->setEnv('ENGAGE_OUTBOUND_FINANCE_S2S_SECRET', str_repeat('s', 40));
        $this->setEnv('FINANCE_S2S_ENVIRONMENT', 'local');
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'local');
        $this->db()->table('engage_settings')->where('key', 'seller_order_payment_instructions')->update(['value_json' => json_encode('HDFC Bank 50200012345678, IFSC HDFC0001234')]);
        $s = $this->verifiedSeller();

        $o = $this->json($this->order($s['session'], [['offer_code' => 'books-pro', 'quantity' => 1]]))['data'];
        $this->assertSame(['link', null], [$o['payment']['mode'], $o['payment']['link_url']]);
        $this->assertStringContainsString('could not be raised just now', (string) $o['payment']['link_problem'], 'Finance unreachable: try again');
        $this->assertSame('HDFC Bank 50200012345678, IFSC HDFC0001234', $o['payment']['instructions'], 'bank transfer meanwhile');
        $staff = $this->json($this->asStaff($this->sales, 'get', 'v1/seller-licence-orders/' . $o['order_ref']))['data'];
        $this->assertStringStartsWith('finance_unreachable', (string) $staff['link_error'], 'staff see Finance\'s answer');

        $this->db()->table('engage_seller_licence_orders')->where('order_ref', $o['order_ref'])->update(['link_error' => 'gstin_state_mismatch: The GSTIN is registered in …']);
        $again = $this->json($this->portal('get', 'licence-orders/' . $o['order_ref'], [], $s['session']))['data']['order'];
        $this->assertStringContainsString('billing details', (string) $again['payment']['link_problem'], 'refused: bank transfer or Aicountly');
        $this->assertStringNotContainsString('GSTIN is registered', json_encode($again), 'Finance\'s own words stay with staff');
    }

    public function testVoidCancelAndExpiry(): void
    {
        $s = $this->verifiedSeller();
        $this->customer();
        $this->paidOrder($s['session'], 1, 'books-pro', 'UTR0303');
        $unit = $this->units($s['session'])[0]['unit_ref'];
        $this->assertSame(422, $this->code($this->asStaff($this->sales, 'post', "v1/seller-licence-units/{$unit}/void", ['reason' => ''])));
        $this->assertSame(200, $this->code($this->asStaff($this->sales, 'post', "v1/seller-licence-units/{$unit}/void", ['reason' => 'Refunded on request'])));
        $v = $this->applyUnit($s['session'], $unit, 'asha@example.com');
        $this->assertSame(409, $this->code($v));
        $this->assertSame('unit_void', $this->json($v)['code']);

        $o = $this->json($this->order($s['session'], [['offer_code' => 'books-lite', 'quantity' => 1]]))['data'];
        $this->assertSame(200, $this->code($this->portal('post', "licence-orders/{$o['order_ref']}/cancel", ['reason' => 'Ordered the wrong plan'], $s['session'])));
        $late = $this->asStaff($this->sales, 'post', "v1/seller-licence-orders/{$o['order_ref']}/confirm-payment", ['payment_ref' => 'UTR9', 'amount' => '5900.00', 'invoice_no' => 'INV/9']);
        $this->assertSame(409, $this->code($late));
        $this->assertSame('order_closed', $this->json($late)['code']);

        $o2 = $this->json($this->order($s['session'], [['offer_code' => 'books-lite', 'quantity' => 1]]))['data'];
        $this->db()->query("UPDATE engage_seller_licence_orders SET expires_at = NOW() - INTERVAL '1 minute' WHERE order_ref = ?", [$o2['order_ref']]);
        $this->assertSame('expired', $this->json($this->portal('get', "licence-orders/{$o2['order_ref']}", [], $s['session']))['data']['order']['status'], 'reads expire before the job');
        $this->assertSame(1, (new \App\Services\SellerLicences\SellerLicenceOrderService())->expireDue());
        $paid = $this->asStaff($this->sales, 'post', "v1/seller-licence-orders/{$o2['order_ref']}/confirm-payment", ['payment_ref' => 'UTR10', 'amount' => '5900.00', 'invoice_no' => 'INV/10']);
        $this->assertSame(200, $this->code($paid), 'a late payment of an expired order still settles it');
    }
}

<?php

namespace Tests\Feature;

use Tests\Support\SellerTestCase;

/**
 * Seller commission on the net (D-C1, docs/COUPONS.md §8): basis = net after discount, before GST — from the quote's
 * committed redemption, else a typed net amount, else (as before) the amount paid. Seller-owned coupons follow the
 * referral / 90-day protection rules.
 */
final class CouponCommissionTest extends SellerTestCase
{
    private string $sales;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sales = $this->staff(['sales_manager'], 'sales@aicountly.org');
        $this->admin = $this->staff(['super_admin'], 'admin@aicountly.org');
        $this->assertSame(201, $this->code($this->asStaff($this->sales, 'post', 'v1/licence-types', ['code' => 'standard', 'label' => 'Standard', 'lifecycle_kind' => 'paid'])));
        $o = $this->asStaff($this->sales, 'post', 'v1/offers', ['offer_code' => 'books-annual', 'product_code' => 'books', 'licence_type_code' => 'standard',
            'label' => 'Books annual', 'price_paise' => 1180000, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331', 'term_months' => 12, 'term_days' => 0]);
        $this->assertSame(201, $this->code($o), (string) $o->response()->getBody());
        $this->asStaff($this->sales, 'post', 'v1/offers/' . $this->json($o)['data']['id'] . '/publish');
        $p = $this->json($this->asStaff($this->sales, 'post', 'v1/commission-plans', ['plan_code' => 'std', 'name' => 'Standard',
            'rules' => ['type' => 'percent_of_first_payment', 'percent' => '10'], 'product_scope' => ['books'], 'hold_days' => 15]))['data'];
        $this->asStaff($this->sales, 'post', "v1/commission-plans/{$p['id']}/publish");
    }

    private function coupon(array $over = []): array
    {
        $r = $this->asStaff($this->sales, 'post', 'v1/coupons', array_merge(['code_kind' => 'shared', 'label' => 'Twenty', 'discount_type' => 'percent',
            'percent_bp' => 2000, 'code' => 'TWENTY26'], $over));
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $c = $this->json($r)['data'];
        $this->assertSame(200, $this->code($this->asStaff($this->admin, 'post', "v1/coupons/{$c['id']}/activate")));

        return $c;
    }

    private function lead(string $email): int
    {
        $this->db()->table('engage_leads')->insert(['name' => 'Customer', 'email' => $email, 'lead_code' => 'LD-' . substr(md5($email), 0, 8), 'interested_product' => 'books']);

        return (int) $this->db()->insertID();
    }

    public function testSellerCouponAttributesAnUnprotectedLeadAndCommissionIsOnTheNet(): void
    {
        $s = $this->activeSellerSession('ravi@example.com');
        $c = $this->coupon(['seller_code' => $s['seller_code'], 'code' => 'RAVI20']);
        $this->assertSame($s['seller_code'], $c['seller']['seller_code']);
        $leadId = $this->lead('cust@x.example');

        $q = $this->asStaff($this->sales, 'post', 'v1/quotes', ['cmp_id' => 42, 'offer_code' => 'books-annual', 'coupon_code' => 'RAVI20', 'lead_id' => $leadId]);
        $this->assertSame(201, $this->code($q), (string) $q->response()->getBody());
        $q = $this->json($q)['data'];
        $lead = $this->db()->table('engage_leads')->where('id', $leadId)->get()->getRowArray();
        $this->assertSame($s['seller_id'], (int) $lead['seller_id'], 'referral via the seller-owned coupon');
        $this->assertSame('referral', $lead['seller_attribution']);
        $this->assertNotNull($lead['seller_protection_expires_at']);

        // Licence issued with the quote → committed; payment confirmed with quote_ref → basis = taxable value ₹8,000.
        $db = \Config\Database::connect();
        $db->transBegin();
        \App\Services\Coupons\CouponLicenceHook::afterLicenceWrite($db, 'issued', ['licence_ref' => 'LIC-000001', 'offer_code' => 'books-annual', 'cmp_id' => 42], ['quote_ref' => $q['quote_ref']]);
        $db->transCommit();
        $red = $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $q['quote_ref'])->get()->getRowArray();
        $this->assertSame('committed', $red['state']);
        $this->assertSame($s['seller_id'], (int) $red['seller_id']);

        $this->asStaff($this->sales, 'post', "v1/leads/{$leadId}/move-stage", ['stage' => 'converted']);
        $pay = $this->asStaff($this->sales, 'post', "v1/leads/{$leadId}/payments", ['amount' => '9440.00', 'payment_ref' => 'AIC-26-27-000123', 'quote_ref' => strtolower($q['quote_ref'])]);
        $this->assertSame(201, $this->code($pay), (string) $pay->response()->getBody());
        $p = $this->json($pay)['data']['payment'];
        $this->assertSame([800000, 'net_before_gst', 1180000, 236000, $q['quote_ref']], [$p['basis_paise'], $p['basis_kind'], $p['list_paise'], $p['discount_paise'], $p['quote_ref']]);
        $this->assertSame('books', $p['product_code']);
        $e = $this->db()->table('engage_commission_entries')->where('lead_id', $leadId)->get()->getRowArray();
        $this->assertSame('8000.00', $e['basis_amount'], 'never the list price, never including GST');
        $this->assertSame('800.00', $e['amount']);
        $this->assertSame('net_before_gst', $e['basis_kind']);

        $rep = $this->json($this->asStaff($this->sales, 'get', 'v1/coupon-report'))['data'];
        $this->assertSame($s['seller_code'], $rep['by_seller'][0]['seller_code']);
        $this->assertSame(944000, $rep['by_seller'][0]['net_paise']);
    }

    public function testSellerCouponNeverOverridesAnotherSellersProtection(): void
    {
        $a = $this->activeSellerSession('anita@example.com');
        $code = $this->json($this->portal('post', 'leads', ['name' => 'Cust', 'email' => 'prot@x.example', 'interest' => 'books', 'consent_confirmed' => true], $a['session']))['data']['lead']['lead_code'];
        $leadId = (int) $this->db()->table('engage_leads')->where('lead_code', $code)->get()->getRow()->id;
        $b = $this->approvedSeller('bala@example.com');
        $this->coupon(['seller_code' => $b['seller_code'], 'code' => 'BALA20']);

        $q = $this->json($this->asStaff($this->sales, 'post', 'v1/quotes', ['cmp_id' => 7, 'offer_code' => 'books-annual', 'coupon_code' => 'BALA20', 'lead_id' => $leadId]))['data'];
        $this->assertSame(236000, $q['discount_paise'], 'the discount still applies');
        $this->assertSame($a['seller_id'], (int) $this->db()->table('engage_leads')->where('id', $leadId)->get()->getRow()->seller_id, 'protection kept');
        $this->assertSame($a['seller_id'], (int) $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $q['quote_ref'])->get()->getRow()->seller_id);
        $this->assertSame(1, $this->db()->table('engage_audit_logs')->where('event', 'coupon.seller_referral_ignored')->countAllResults());
    }

    public function testNetAmountAndLegacyBasis(): void
    {
        $l1 = $this->lead('one@x.example');
        $l2 = $this->lead('two@x.example');
        $s = $this->approvedSeller('sam@example.com');
        $this->db()->table('engage_leads')->whereIn('id', [$l1, $l2])->update(['seller_id' => $s['seller_id'], 'seller_attribution' => 'registration']);
        foreach ([$l1, $l2] as $l) {
            $this->asStaff($this->sales, 'post', "v1/leads/{$l}/move-stage", ['stage' => 'converted']);
        }
        $bad = $this->asStaff($this->sales, 'post', "v1/leads/{$l1}/payments", ['amount' => '11800.00', 'payment_ref' => 'X-1', 'net_amount' => '12000.00']);
        $this->assertSame(422, $this->code($bad));
        $this->assertArrayHasKey('net_amount', $this->json($bad)['details']);
        $this->assertSame(422, $this->code($this->asStaff($this->sales, 'post', "v1/leads/{$l1}/payments", ['amount' => '11800.00', 'payment_ref' => 'X-2', 'quote_ref' => 'QTE-999999'])));

        $p1 = $this->json($this->asStaff($this->sales, 'post', "v1/leads/{$l1}/payments", ['amount' => '11800.00', 'payment_ref' => 'X-3', 'net_amount' => '10000.00']))['data']['payment'];
        $this->assertSame([1000000, 'net_before_gst'], [$p1['basis_paise'], $p1['basis_kind']]);
        $this->assertSame('1000.00', $this->db()->table('engage_commission_entries')->where('lead_id', $l1)->get()->getRow()->amount);

        $p2 = $this->json($this->asStaff($this->sales, 'post', "v1/leads/{$l2}/payments", ['amount' => '5000.00', 'payment_ref' => 'X-4']))['data']['payment'];
        $this->assertSame([500000, 'payment_amount'], [$p2['basis_paise'], $p2['basis_kind']], 'as before coupons: the amount paid');
        $e2 = $this->db()->table('engage_commission_entries')->where('lead_id', $l2)->get()->getRowArray();
        $this->assertSame('500.00', $e2['amount']);
        $this->assertSame('payment_amount', $e2['basis_kind']);
    }
}

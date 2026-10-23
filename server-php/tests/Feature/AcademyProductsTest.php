<?php

namespace Tests\Feature;

use App\Database\Migrations\AddAcademyProducts;
use App\Models\ProductsModel;
use App\Services\ReachOutcomeOutbox;
use Config\Database;
use Config\Services;
use Tests\Support\SellerTestCase;

/**
 * Aicountly Academy products (academy | edge | aspire): the migration / seeder rows, free-text matching at every
 * lead intake, payment confirmation under a plan scoped to the courses, and the revenue-by-product view.
 */
final class AcademyProductsTest extends SellerTestCase
{
    private const REACH_TOKEN = 'reach-inbound-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('REACH_INBOUND_TOKEN', self::REACH_TOKEN);
        Services::injectMock('reachOutcomes', new ReachOutcomeOutbox(static fn (): array => ['status' => 200, 'body' => '{"ok":true}'], 'https://reach.test/api', str_repeat('t', 40)));
    }

    private function reach(array $payload)
    {
        $this->headers = [];

        return $this->withHeaders(['X-Portal-Token' => self::REACH_TOKEN])->withBodyFormat('json')->post('v1/internal/reach/leads', array_merge([
            'source_portal' => 'reach.aicountly.org', 'name' => 'Asha Rao', 'campaign_code' => 'academy-edge-launch', 'campaign_name' => 'Academy Edge launch',
        ], $payload));
    }

    private function lead(string $code): array
    {
        return $this->db()->table('engage_leads')->where('lead_code', $code)->get()->getRowArray();
    }

    public function testMigrationAndSeederGiveOneRowPerAcademyProductAndAreIdempotent(): void
    {
        $rows = $this->db()->table('engage_products')->where('category', 'Academy')->orderBy('sort_order')->get()->getResultArray();
        $this->assertSame(['academy', 'edge', 'aspire'], array_column($rows, 'code'));
        $this->assertSame(['Aicountly Academy', 'Aicountly Edge', 'Aicountly Aspire'], array_column($rows, 'name'));

        // A renamed row is brought back in line; running it again adds nothing.
        $this->db()->table('engage_products')->where('code', 'edge')->update(['name' => 'Edge (old)', 'category' => 'hr', 'sort_order' => 5]);
        (new AddAcademyProducts(Database::forge('tests')))->up();
        (new AddAcademyProducts(Database::forge('tests')))->up();
        $this->seed(\App\Database\Seeds\ProductsSeeder::class);
        $edge = $this->db()->table('engage_products')->where('code', 'edge')->get()->getResultArray();
        $this->assertCount(1, $edge);
        $this->assertSame('Aicountly Edge', $edge[0]['name']);
        $this->assertSame('Academy', $edge[0]['category']);
        $this->assertSame(5, (int) $edge[0]['sort_order'], 'sort order is left as staff set it');
    }

    public function testResolveMatchesCodeOrNameCaseAndSpaceInsensitively(): void
    {
        $m = new ProductsModel();
        foreach (['edge' => 'edge', 'EDGE' => 'edge', 'Aicountly Edge' => 'edge', '  aicountly   EDGE ' => 'edge', 'AICOUNTLY Edge' => 'edge',
            'aspire' => 'aspire', 'Aicountly Aspire' => 'aspire', 'academy' => 'academy', 'Aicountly Academy' => 'academy', 'books' => 'books'] as $text => $code) {
            $this->assertSame($code, $m->resolve($text)['code'] ?? null, $text);
        }
        foreach (['', '   ', null, 'edge programme', 'Aicountly'] as $text) {
            $this->assertNull($m->resolve($text), var_export($text, true));
        }
    }

    public function testSellerRegistrationLinksAcademyProductsFromFreeText(): void
    {
        $s = $this->activeSellerSession();
        foreach (['edge' => 'edge', 'Aicountly Aspire' => 'aspire', 'academy' => 'academy', 'Aicountly Edge' => 'edge', 'Payroll training' => null] as $interest => $code) {
            $email = 'stu' . md5($interest) . '@x.example';
            $r = $this->portal('post', 'leads', ['name' => 'Student', 'email' => $email, 'interest' => $interest, 'consent_confirmed' => true], $s['session']);
            $this->assertSame(201, $this->code($r), $interest);
            $row = $this->lead($this->json($r)['data']['lead']['lead_code']);
            $this->assertSame($code, $row['interested_product'], $interest);
            $this->assertSame($code === null, $row['interested_product_id'] === null, $interest);
            $this->assertSame($interest, $row['subscription_interest']);
        }
    }

    public function testReachIntakeLinksAcademyProductsByCodeOrName(): void
    {
        foreach (['Aicountly Edge' => 'edge', 'aspire' => 'aspire', 'ACADEMY' => 'academy'] as $sent => $code) {
            $r = $this->reach(['email' => 'r' . md5($sent) . '@x.example', 'interested_product' => $sent]);
            $this->assertSame(201, $this->code($r), $sent);
            $row = $this->lead($this->json($r)['data']['lead']['lead_code']);
            $this->assertSame($code, $row['interested_product'], $sent);
            $this->assertNotNull($row['interested_product_id'], $sent);
        }
        // Unknown text is kept as sent, unlinked (unchanged behaviour).
        $row = $this->lead($this->json($this->reach(['email' => 'unknown@x.example', 'interested_product' => 'tally-course']))['data']['lead']['lead_code']);
        $this->assertSame('tally-course', $row['interested_product']);
        $this->assertNull($row['interested_product_id']);
    }

    public function testCoursePaymentAccruesUnderAnAcademyPlanAndShowsInRevenueView(): void
    {
        $s = $this->activeSellerSession();
        $code = $this->json($this->portal('post', 'leads', ['name' => 'Student', 'email' => 'edge.student@x.example', 'interest' => 'Aicountly Edge', 'consent_confirmed' => true], $s['session']))['data']['lead']['lead_code'];
        $leadId = (int) $this->lead($code)['id'];
        $sales = $this->staff(['sales_manager'], 'sales@aicountly.org');
        $day = gmdate('Y-m-d', time() - 86400);
        $dayBefore = gmdate('Y-m-d', time() - 2 * 86400);

        $plan = $this->json($this->asStaff($sales, 'post', 'v1/commission-plans', ['plan_code' => 'academy-courses', 'name' => 'Academy courses',
            'rules' => ['type' => 'percent_of_first_payment', 'percent' => '10'], 'product_scope' => ['edge', 'aspire'], 'hold_days' => 15]))['data'];
        $this->assertSame('active', $this->json($this->asStaff($sales, 'post', "v1/commission-plans/{$plan['id']}/publish"))['data']['status']);

        $this->asStaff($sales, 'post', "v1/leads/{$leadId}/move-stage", ['stage' => 'converted']);
        // product_code typed as a name still lands on the code the plan covers.
        $pay = $this->asStaff($sales, 'post', "v1/leads/{$leadId}/payments", ['amount' => '50000.00', 'payment_ref' => 'pay_EdgeFee0001', 'product_code' => 'Edge', 'paid_at' => $day]);
        $this->assertSame(201, $this->code($pay));
        $body = $this->json($pay)['data'];
        $this->assertSame('edge', $body['payment']['product_code']);
        $this->assertSame(1, $body['accrual']['accrued']);
        $this->assertSame('5000.00', $body['entry']['amount']);

        // An `academy` (umbrella) payment is outside the plan's scope: recorded, nothing accrued.
        $code2 = $this->json($this->portal('post', 'leads', ['name' => 'Student 2', 'email' => 'umbrella@x.example', 'interest' => 'academy', 'consent_confirmed' => true], $s['session']))['data']['lead']['lead_code'];
        $lead2 = (int) $this->lead($code2)['id'];
        $this->asStaff($sales, 'post', "v1/leads/{$lead2}/move-stage", ['stage' => 'converted']);
        $p2 = $this->json($this->asStaff($sales, 'post', "v1/leads/{$lead2}/payments", ['amount' => '1000', 'payment_ref' => 'pay_Umbrella0001']))['data'];
        $this->assertSame('academy', $p2['payment']['product_code']);
        $this->assertSame(0, $p2['accrual']['accrued']);

        // Revenue view: role-gated, grouped by product, refunds counted apart.
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['seller_manager'], 'sm@aicountly.org'), 'get', 'v1/lead-payments/by-product?category=Academy')));
        $this->asStaff($sales, 'post', "v1/lead-payments/{$p2['payment']['id']}/refund", ['reason' => 'Admission withdrawn']);
        $rev = $this->json($this->asStaff($sales, 'get', 'v1/lead-payments/by-product?category=academy'))['data'];
        $this->assertSame('50000.00', $rev['total_confirmed_amount']);
        $byCode = array_column($rev['items'], null, 'product_code');
        $this->assertSame(['academy', 'edge'], array_keys($byCode));
        $this->assertSame('50000.00', $byCode['edge']['confirmed_amount']);
        $this->assertSame(1, $byCode['edge']['paying_leads']);
        $this->assertSame('Aicountly Edge', $byCode['edge']['product_name']);
        $this->assertSame(1, $byCode['academy']['refunded_count']);
        $this->assertSame('1000.00', $byCode['academy']['refunded_amount']);

        $this->assertSame([], $this->json($this->asStaff($sales, 'get', 'v1/lead-payments/by-product?product_code=aspire'))['data']['items']);
        $this->assertSame([], $this->json($this->asStaff($sales, 'get', "v1/lead-payments/by-product?product_code=edge&to={$dayBefore}"))['data']['items']);
        $this->assertCount(1, $this->json($this->asStaff($sales, 'get', "v1/lead-payments/by-product?product_code=edge&from={$day}&to={$day}"))['data']['items']);
        $this->assertSame(422, $this->code($this->asStaff($sales, 'get', 'v1/lead-payments/by-product?from=25-10-2026')));
    }
}

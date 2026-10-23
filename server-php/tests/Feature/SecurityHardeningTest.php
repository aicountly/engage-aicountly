<?php

namespace Tests\Feature;

use Tests\Support\CouponTestCase;

/**
 * Security audit, Oct 2026 — regression tests for the Engage fixes:
 *   - coupon four-eyes also covers whoever edited the draft;
 *   - coupon code CSV export neutralises spreadsheet formulas;
 *   - discount requests / approvals / credit ledger: no mass assignment of decisions, approvals or money;
 *   - products and plans (the licensing catalogue) are written by super_admin | sales_manager only;
 *   - logout revokes the bearer token.
 */
final class SecurityHardeningTest extends CouponTestCase
{
    public function testAnEditorCannotApproveTheDraftTheyChanged(): void
    {
        $c = $this->draft(); // created by maker (sales_manager)
        $other = $this->staff(['sales_manager'], 'other-sm@aicountly.org');

        // The other sales manager raises the discount of the maker's draft…
        $e = $this->asStaff($other, 'put', "v1/coupons/{$c['id']}", ['percent_bp' => 9000, 'version' => $c['version']]);
        $this->assertSame(200, $this->code($e), (string) $e->response()->getBody());
        $this->assertContains((int) $this->db()->table('engage_users')->where('email', 'other-sm@aicountly.org')->get()->getRow()->id,
            $this->json($e)['data']['edited_by']);

        // …and may not approve it themselves (created_by ≠ approver is not enough).
        $self = $this->asStaff($other, 'post', "v1/coupons/{$c['id']}/activate");
        $this->assertSame(409, $this->code($self));
        $this->assertSame('four_eyes', $this->json($self)['code']);
        $this->assertSame('draft', $this->db()->table('engage_coupons')->where('id', $c['id'])->get()->getRow()->status);

        // The creator may not either; a third sales manager (neither created nor edited) may.
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/activate")));
        $third = $this->staff(['sales_manager'], 'third-sm@aicountly.org');
        $ok = $this->asStaff($third, 'post', "v1/coupons/{$c['id']}/activate");
        $this->assertSame(200, $this->code($ok), (string) $ok->response()->getBody());
        $this->assertSame('active', $this->json($ok)['data']['status']);
    }

    public function testASuperAdminMayStillApproveTheirOwnEdit(): void
    {
        $c = $this->draft();
        $admin = $this->checker();
        $this->assertSame(200, $this->code($this->asStaff($admin, 'put', "v1/coupons/{$c['id']}", ['label' => 'Edited by admin'])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/coupons/{$c['id']}/activate")), 'documented super_admin exception');
    }

    public function testCodeExportNeutralisesFormulas(): void
    {
        $c = $this->draft(['label' => '=HYPERLINK("https://evil.example","x")']);
        $r = $this->asStaff($this->maker(), 'get', "v1/coupons/{$c['id']}/codes.csv");
        $this->assertSame(200, $this->code($r));
        $csv = (string) $r->response()->getBody();
        $this->assertStringContainsString('"\'=HYPERLINK(', $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertStringContainsString('"DIWALI26"', $csv, 'ordinary cells unchanged');
    }

    public function testDiscountRequestsCannotBeCreatedOrChangedAsDecided(): void
    {
        $member = $this->staff(['member'], 'dr-member@aicountly.org');
        $r = $this->asStaff($member, 'post', 'v1/discount-requests', ['discount_percent' => 90, 'justification' => 'please',
            'status' => 'approved', 'decided_by' => 1, 'decided_at' => '2026-10-01 00:00:00', 'requested_by' => 999]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $row = $this->db()->table('engage_discount_requests')->where('id', $this->json($r)['data']['id'])->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['decided_by']);
        $this->assertNotSame('999', (string) $row['requested_by']);

        // Approved by a sales manager at 10 %…
        $this->db()->table('engage_discount_requests')->where('id', $row['id'])->update(['discount_percent' => '10.00']);
        $this->assertSame(200, $this->code($this->asStaff($this->maker(), 'post', "v1/discount-requests/{$row['id']}/approve")));
        // …a member cannot then raise it to 90 %.
        $edit = $this->asStaff($member, 'put', "v1/discount-requests/{$row['id']}", ['discount_percent' => 90]);
        $this->assertSame(409, $this->code($edit));
        $this->assertSame('10.00', (string) $this->db()->table('engage_discount_requests')->where('id', $row['id'])->get()->getRow()->discount_percent);
    }

    public function testApprovalsCannotBeForgedOrAlteredAfterTheDecision(): void
    {
        $member = $this->staff(['member'], 'ap-member@aicountly.org');
        $memberId = (int) $this->db()->table('engage_users')->where('email', 'ap-member@aicountly.org')->get()->getRow()->id;
        $r = $this->asStaff($member, 'post', 'v1/approvals', ['kind' => 'discount', 'risk_level' => 'high', 'requested_by' => 1, 'status' => 'approved',
            'payload' => ['discount_percent' => 5]]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $a = $this->json($r)['data'];
        $this->assertSame('pending', $a['status']);
        $this->assertSame($memberId, (int) $a['requested_by'], 'requester is the caller');

        $this->assertSame(200, $this->code($this->asStaff($this->maker(), 'post', "v1/approvals/{$a['id']}/approve")));
        $tamper = $this->asStaff($member, 'put', "v1/approvals/{$a['id']}", ['payload' => ['discount_percent' => 95]]);
        $this->assertSame(409, $this->code($tamper));
        $stored = json_decode((string) $this->db()->table('engage_approval_requests')->where('id', $a['id'])->get()->getRow()->payload, true);
        $this->assertSame(5, $stored['discount_percent']);
    }

    public function testCreditLedgerApprovalCannotBeSkipped(): void
    {
        $member = $this->staff(['member'], 'cl-member@aicountly.org');
        $r = $this->asStaff($member, 'post', 'v1/credit-ledger', ['party_type' => 'customer', 'party_reference' => 'C-1', 'direction' => 'credit',
            'amount' => 500000, 'status' => 'posted', 'approved_by' => 1, 'created_by' => 1]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $row = $this->json($r)['data'];
        $this->assertSame('pending_approval', $row['status'], 'a large credit waits for approval');
        $this->assertNull($row['approved_by']);

        $small = $this->json($this->asStaff($member, 'post', 'v1/credit-ledger', ['party_type' => 'customer', 'party_reference' => 'C-1',
            'direction' => 'credit', 'amount' => 10]))['data'];
        $this->assertSame('posted', $small['status']);
        // A member can still fix the remarks, but not the money or the state.
        $u = $this->asStaff($member, 'put', "v1/credit-ledger/{$small['id']}", ['amount' => 900000, 'status' => 'posted', 'remarks' => 'typo fixed']);
        $this->assertSame(200, $this->code($u));
        $after = $this->db()->table('engage_credit_ledger')->where('id', $small['id'])->get()->getRowArray();
        $this->assertSame(10.0, (float) $after['amount']);
        $this->assertSame('typo fixed', $after['remarks']);
        $this->asStaff($member, 'put', "v1/credit-ledger/{$row['id']}", ['status' => 'posted']);
        $this->assertSame('pending_approval', $this->db()->table('engage_credit_ledger')->where('id', $row['id'])->get()->getRow()->status);
    }

    public function testCatalogueWritesNeedASalesManager(): void
    {
        foreach (['member', 'kyc_reviewer', 'seller_manager'] as $role) {
            $t = $this->staff([$role], "cat-{$role}@aicountly.org");
            $this->assertSame(200, $this->code($this->asStaff($t, 'get', 'v1/products')), "{$role} reads products");
            $this->assertSame(200, $this->code($this->asStaff($t, 'get', 'v1/plans')), "{$role} reads plans");
            $this->assertSame(403, $this->code($this->asStaff($t, 'post', 'v1/products', ['code' => 'x-' . $role, 'name' => 'X'])), $role);
            $this->assertSame(403, $this->code($this->asStaff($t, 'put', 'v1/products/1', ['is_active' => false])), $role);
            $this->assertSame(403, $this->code($this->asStaff($t, 'delete', 'v1/products/1')), $role);
            $this->assertSame(403, $this->code($this->asStaff($t, 'post', 'v1/plans', ['code' => 'p-' . $role, 'name' => 'P'])), $role);
        }
        $r = $this->asStaff($this->maker(), 'post', 'v1/products', ['code' => 'sec-test', 'name' => 'Security test']);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
    }

    public function testLogoutRevokesTheToken(): void
    {
        $t = $this->staff(['member'], 'logout@aicountly.org');
        $this->assertSame(200, $this->code($this->asStaff($t, 'get', 'v1/me')));
        $this->assertSame(200, $this->code($this->asStaff($t, 'post', 'v1/auth/logout')));
        $this->assertSame(401, $this->code($this->asStaff($t, 'get', 'v1/me')), 'the token no longer works after logout');
    }
}

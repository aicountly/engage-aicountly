<?php

namespace Tests\Feature;

use Tests\Support\CouponTestCase;

/**
 * The legacy discount-request and approval decisions are role-gated (super_admin | sales_manager); before, any signed-in
 * role could approve (audit: Routes.php discount-requests/*\/approve|reject, approvals/*\/approve|reject|execute).
 * A plain edit (PUT) cannot be used to make the decision either.
 */
final class ApprovalRightsTest extends CouponTestCase
{
    private function discountRequest(): int
    {
        $this->db()->table('engage_discount_requests')->insert(['discount_percent' => '10.00', 'justification' => 'Volume deal', 'status' => 'pending']);

        return (int) $this->db()->insertID();
    }

    private function approval(): int
    {
        $this->db()->table('engage_approval_requests')->insert(['kind' => 'discount', 'status' => 'pending', 'risk_level' => 'high']);

        return (int) $this->db()->insertID();
    }

    public function testDiscountRequestDecisionsNeedTheRole(): void
    {
        $id = $this->discountRequest();
        foreach (['member', 'seller_manager', 'kyc_reviewer'] as $role) {
            $t = $this->staff([$role], "{$role}@aicountly.org");
            $this->assertSame(403, $this->code($this->asStaff($t, 'post', "v1/discount-requests/{$id}/approve")), $role);
            $this->assertSame(403, $this->code($this->asStaff($t, 'post', "v1/discount-requests/{$id}/reject")), $role);
            // Editing cannot flip the decision either (other fields still editable as before).
            $this->assertSame(200, $this->code($this->asStaff($t, 'put', "v1/discount-requests/{$id}", ['status' => 'approved', 'decided_by' => 1, 'justification' => 'Updated'])));
            $row = $this->db()->table('engage_discount_requests')->where('id', $id)->get()->getRowArray();
            $this->assertSame('pending', $row['status'], $role);
            $this->assertSame('Updated', $row['justification']);
        }
        // Still readable by everyone, as before.
        $this->assertSame(200, $this->code($this->asStaff($this->staff(['member'], 'm2@aicountly.org'), 'get', 'v1/discount-requests')));

        $ok = $this->asStaff($this->maker(), 'post', "v1/discount-requests/{$id}/approve", ['notes' => 'ok']);
        $this->assertSame(200, $this->code($ok));
        $this->assertSame('approved', $this->db()->table('engage_discount_requests')->where('id', $id)->get()->getRow()->status);
        $id2 = $this->discountRequest();
        $this->assertSame(200, $this->code($this->asStaff($this->checker(), 'post', "v1/discount-requests/{$id2}/reject")));
        $this->assertSame('rejected', $this->db()->table('engage_discount_requests')->where('id', $id2)->get()->getRow()->status);
    }

    public function testApprovalDecisionsNeedTheRole(): void
    {
        $id = $this->approval();
        foreach (['member', 'seller_manager'] as $role) {
            $t = $this->staff([$role], "{$role}@aicountly.org");
            foreach (['approve', 'reject', 'execute'] as $action) {
                $this->assertSame(403, $this->code($this->asStaff($t, 'post', "v1/approvals/{$id}/{$action}")), "{$role} {$action}");
            }
            $this->asStaff($t, 'put', "v1/approvals/{$id}", ['status' => 'approved']);
            $this->assertSame('pending', $this->db()->table('engage_approval_requests')->where('id', $id)->get()->getRow()->status);
        }
        $this->assertSame(200, $this->code($this->asStaff($this->staff(['member'], 'm3@aicountly.org'), 'get', 'v1/approvals')));
        $a = $this->asStaff($this->maker(), 'post', "v1/approvals/{$id}/approve");
        $this->assertSame(200, $this->code($a), (string) $a->response()->getBody());
        $this->assertSame('approved', $this->db()->table('engage_approval_requests')->where('id', $id)->get()->getRow()->status);
        $this->assertSame(200, $this->code($this->asStaff($this->checker(), 'post', "v1/approvals/{$id}/execute")));
        $id2 = $this->approval();
        $this->assertSame(200, $this->code($this->asStaff($this->checker(), 'post', "v1/approvals/{$id2}/reject")));
    }
}

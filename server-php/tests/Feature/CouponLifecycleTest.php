<?php

namespace Tests\Feature;

use Tests\Support\CouponTestCase;

/**
 * Coupons: rights, draft → active (four-eyes) ⇄ paused → retired, immutability (DB guard), codes (shared / bulk with
 * check characters), CSV export, report. docs/COUPONS.md §1–3.
 */
final class CouponLifecycleTest extends CouponTestCase
{
    public function testRightsAreEnforcedPerRole(): void
    {
        $member = $this->staff(['member'], 'member@aicountly.org');
        $kyc = $this->staff(['kyc_reviewer'], 'kyc@aicountly.org');
        $sellerMgr = $this->staff(['seller_manager'], 'smgr@aicountly.org');

        $c = $this->draft();
        foreach ([$member, $kyc] as $t) {
            $this->assertSame(403, $this->code($this->asStaff($t, 'get', 'v1/coupons')));
            $this->assertSame(403, $this->code($this->asStaff($t, 'get', "v1/coupons/{$c['id']}/codes.csv")));
            $this->assertSame(403, $this->code($this->asStaff($t, 'get', 'v1/coupon-report')));
        }
        // seller_manager: view and distribute (export), nothing else.
        $this->assertSame(200, $this->code($this->asStaff($sellerMgr, 'get', 'v1/coupons')));
        $this->assertSame(200, $this->code($this->asStaff($sellerMgr, 'get', "v1/coupons/{$c['id']}")));
        $this->assertSame(200, $this->code($this->asStaff($sellerMgr, 'get', "v1/coupons/{$c['id']}/codes.csv")));
        $this->assertSame(200, $this->code($this->asStaff($sellerMgr, 'get', 'v1/coupon-redemptions')));
        foreach ([
            ['post', 'v1/coupons', ['code_kind' => 'shared', 'label' => 'x', 'discount_type' => 'flat', 'flat_paise' => 100]],
            ['put', "v1/coupons/{$c['id']}", ['label' => 'y']],
            ['delete', "v1/coupons/{$c['id']}", []],
            ['post', "v1/coupons/{$c['id']}/activate", []],
            ['post', "v1/coupons/{$c['id']}/pause", []],
            ['post', "v1/coupons/{$c['id']}/retire", []],
            ['post', "v1/coupons/{$c['id']}/codes", ['code' => 'ANOTHER1']],
            ['post', 'v1/quotes', ['cmp_id' => 42, 'offer_code' => 'books-annual']],
            ['post', 'v1/coupon-redemptions/1/release', ['reason' => 'x']],
            ['post', 'v1/coupon-redemptions/1/reverse', ['reason' => 'x']],
        ] as [$m, $path, $body]) {
            $this->assertSame(403, $this->code($this->asStaff($sellerMgr, $m, $path, $body)), "{$m} {$path} must be refused to seller_manager");
            $this->assertSame(403, $this->code($this->asStaff($member, $m, $path, $body)), "{$m} {$path} must be refused to member");
        }
    }

    public function testValidationOfTheRule(): void
    {
        $r = $this->asStaff($this->maker(), 'post', 'v1/coupons', ['code_kind' => 'both', 'label' => '', 'discount_type' => 'percent', 'percent_bp' => 0,
            'max_redemptions_total' => 1, 'max_redemptions_per_holder' => 2, 'stackable' => true, 'applies_to' => ['product_codes' => ['nope']],
            'valid_from' => '2026-12-01', 'valid_to' => '2026-11-01', 'code' => 'x1']);
        $this->assertSame(422, $this->code($r));
        $d = $this->json($r)['details'];
        foreach (['code_kind', 'label', 'percent_bp', 'max_redemptions_per_holder', 'stackable', 'applies_to', 'valid_to'] as $f) {
            $this->assertArrayHasKey($f, $d, $f);
        }
        $flat = $this->asStaff($this->maker(), 'post', 'v1/coupons', ['code_kind' => 'single_use', 'label' => 'Flat', 'discount_type' => 'flat']);
        $this->assertArrayHasKey('flat_paise', $this->json($flat)['details']);
        // percent as a string "12.5" → 1250 bp; the code is stored normalised.
        $ok = $this->draft(['percent' => '12.5', 'percent_bp' => null, 'code' => ' Fest-ive 25 ']);
        $this->assertSame(1250, $ok['percent_bp']);
        $this->assertSame(['FESTIVE25'], $this->codesOf($ok['id']));
        // the same code (any case) cannot be used twice
        $dup = $this->asStaff($this->maker(), 'post', 'v1/coupons', ['code_kind' => 'shared', 'label' => 'Dup', 'discount_type' => 'flat', 'flat_paise' => 500, 'code' => 'festive-25']);
        $this->assertSame(422, $this->code($dup));
        $this->assertArrayHasKey('code', $this->json($dup)['details']);
    }

    public function testFourEyesActivationAndLifecycle(): void
    {
        $c = $this->draft(['valid_to' => '2099-12-31']);
        $this->assertSame('draft', $c['status']);
        // valid_to typed as a date = last day it works → 00:00 IST the day after (= 18:30 UTC that day)
        $this->assertSame('2099-12-31T18:30:00Z', $c['valid_to']);

        // Draft edits need the current version.
        $stale = $this->asStaff($this->maker(), 'put', "v1/coupons/{$c['id']}", ['label' => 'Diwali 25 %', 'version' => 99]);
        $this->assertSame(409, $this->code($stale));
        $this->assertSame('version_conflict', $this->json($stale)['code']);
        $e = $this->asStaff($this->maker(), 'put', "v1/coupons/{$c['id']}", ['label' => 'Diwali 25 %', 'percent_bp' => 2500, 'version' => 1]);
        $this->assertSame(200, $this->code($e));
        $this->assertSame(2, $this->json($e)['data']['version']);

        // The maker cannot approve their own coupon; another sales manager or an administrator can.
        $self = $this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/activate");
        $this->assertSame(409, $this->code($self));
        $this->assertSame('four_eyes', $this->json($self)['code']);
        $other = $this->staff(['sales_manager'], 'other-mgr@aicountly.org');
        $a = $this->asStaff($other, 'post', "v1/coupons/{$c['id']}/activate");
        $this->assertSame(200, $this->code($a), (string) $a->response()->getBody());
        $a = $this->json($a)['data'];
        $this->assertSame('active', $a['status']);
        $this->assertSame('other-mgr@aicountly.org', $a['approved_by_email']);
        $this->assertNotNull($a['approved_at']);

        // Active rules are immutable: the API refuses, and so does the database.
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'put', "v1/coupons/{$c['id']}", ['label' => 'x'])));
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'delete', "v1/coupons/{$c['id']}")));
        try {
            $this->db()->query('UPDATE engage_coupons SET percent_bp = 9000 WHERE id = ?', [$c['id']]);
            $this->fail('DB guard must refuse changing an active rule');
        } catch (\Throwable $ex) {
            $this->assertStringContainsString('immutable', $ex->getMessage());
        }
        try {
            $this->db()->query('DELETE FROM engage_coupons WHERE id = ?', [$c['id']]);
            $this->fail('DB guard must refuse deleting an active coupon');
        } catch (\Throwable $ex) {
            $this->assertStringContainsString('cannot be deleted', $ex->getMessage());
        }

        // pause ⇄ resume, then retire (final).
        $this->assertSame('paused', $this->json($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/pause"))['data']['status']);
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/pause")));
        $this->assertSame('active', $this->json($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/activate"))['data']['status']);
        $this->assertSame('retired', $this->json($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/retire", ['reason' => 'Campaign over']))['data']['status']);
        $this->assertSame(409, $this->code($this->asStaff($this->checker(), 'post', "v1/coupons/{$c['id']}/activate")));
        try {
            $this->db()->query("UPDATE engage_coupons SET status = 'active' WHERE id = ?", [$c['id']]);
            $this->fail('retired is final');
        } catch (\Throwable $ex) {
            $this->assertStringContainsString('cannot move from retired', $ex->getMessage());
        }

        $events = array_column($this->db()->table('engage_audit_logs')->where('subject_kind', 'coupon')->where('subject_id', (string) $c['id'])->get()->getResultArray(), 'event');
        foreach (['coupon.created', 'coupon.updated', 'coupon.activated', 'coupon.paused', 'coupon.resumed', 'coupon.retired'] as $ev) {
            $this->assertContains($ev, $events);
        }
    }

    public function testActivationNeedsACodeAndASuperAdminMayApproveTheirOwn(): void
    {
        $admin = $this->checker();
        $r = $this->asStaff($admin, 'post', 'v1/coupons', ['code_kind' => 'single_use', 'label' => 'Bulk', 'discount_type' => 'flat', 'flat_paise' => 50000]);
        $id = $this->json($r)['data']['id'];
        $no = $this->asStaff($admin, 'post', "v1/coupons/{$id}/activate");
        $this->assertSame('no_codes', $this->json($no)['code']);
        $this->assertSame(201, $this->code($this->asStaff($admin, 'post', "v1/coupons/{$id}/codes", ['count' => 3])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/coupons/{$id}/activate")), 'super_admin approves their own (recorded)');
        // A draft discarded never leaves a trace in the ledger; an activated coupon can only be retired.
        $d = $this->draft(['code' => 'GONE2026']);
        $this->assertSame(200, $this->code($this->asStaff($this->maker(), 'delete', "v1/coupons/{$d['id']}")));
        $this->assertSame(0, $this->db()->table('engage_coupon_codes')->where('code', 'GONE2026')->countAllResults());
    }

    public function testBulkCodesExportAndVoid(): void
    {
        $c = $this->draft(['code_kind' => 'single_use', 'code' => null, 'label' => 'Partner codes']);
        $bad = $this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/codes", ['count' => 0, 'prefix' => 'A0']);
        $this->assertSame(422, $this->code($bad));
        $this->assertArrayHasKey('count', $this->json($bad)['details']);
        $this->assertArrayHasKey('prefix', $this->json($bad)['details']);
        $this->assertSame(422, $this->code($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/codes", ['count' => 5001])));

        $g = $this->json($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/codes", ['count' => 250, 'prefix' => 'pt']))['data'];
        $this->assertSame(250, $g['count']);
        $codes = $this->codesOf($c['id']);
        $this->assertCount(250, $codes);
        $this->assertCount(250, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertTrue(\App\Services\Coupons\CodeGenerator::hasValidCheck($code, 'PT'), $code);
        }
        $list = $this->json($this->asStaff($this->maker(), 'get', "v1/coupons/{$c['id']}/codes", ['limit' => 10]))['data'];
        $this->assertSame(250, $list['total']);
        $this->assertCount(10, $list['items']);

        // Void one, then export the available ones (seller_manager may distribute).
        $codeId = $list['items'][0]['id'];
        $this->assertSame(200, $this->code($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/codes/{$codeId}/void")));
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/codes/{$codeId}/void")));
        $csv = $this->asStaff($this->staff(['seller_manager'], 'dist@aicountly.org'), 'get', "v1/coupons/{$c['id']}/codes.csv");
        $this->assertSame(200, $this->code($csv));
        $this->assertStringContainsString('text/csv', $csv->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="cpn-', $csv->response()->getHeaderLine('Content-Disposition'));
        $lines = array_values(array_filter(explode("\r\n", (string) $csv->response()->getBody())));
        $this->assertSame('code,display,coupon_ref,label,offer,valid_until,status,batch_ref', $lines[0]);
        $this->assertCount(250, $lines, 'header + 249 available codes');
        $this->assertStringContainsString('"20% off"', $lines[1]);
        $this->assertSame(249, $this->db()->table('engage_coupon_codes')->where('coupon_id', $c['id'])->where('export_count', 1)->countAllResults());
        $audit = $this->db()->table('engage_audit_logs')->where('event', 'coupon.codes_exported')->get()->getRowArray();
        $this->assertSame('dist@aicountly.org', $audit['actor_email']);
        $this->assertSame(249, json_decode((string) $audit['metadata'], true)['count']);
    }

    public function testListFiltersAndReport(): void
    {
        $this->offers();
        $a = $this->activeCoupon(['label' => 'Alpha', 'code' => 'ALPHA2026']);
        $this->draft(['label' => 'Beta', 'code' => 'BETA2026']);
        $all = $this->json($this->asStaff($this->maker(), 'get', 'v1/coupons'))['data']['items'];
        $this->assertCount(2, $all);
        $act = $this->json($this->asStaff($this->maker(), 'get', 'v1/coupons', ['status' => 'active']))['data']['items'];
        $this->assertSame(['Alpha'], array_column($act, 'label'));
        $byCode = $this->json($this->asStaff($this->maker(), 'get', 'v1/coupons', ['q' => 'beta-2026']))['data']['items'];
        $this->assertSame(['Beta'], array_column($byCode, 'label'));

        // An ended active coupon reads expired before the job runs; the job records it.
        $this->db()->query("ALTER TABLE engage_coupons DISABLE TRIGGER trg_engage_coupons_guard");
        $this->db()->query("UPDATE engage_coupons SET valid_from = NOW() - INTERVAL '2 days', valid_to = NOW() - INTERVAL '1 day' WHERE id = ?", [$a['id']]);
        $this->db()->query("ALTER TABLE engage_coupons ENABLE TRIGGER trg_engage_coupons_guard");
        $this->assertSame('expired', $this->json($this->asStaff($this->maker(), 'get', "v1/coupons/{$a['id']}"))['data']['status']);
        $this->assertSame(1, (new \App\Services\Coupons\CouponService())->expireEnded());
        $this->assertSame('expired', $this->db()->table('engage_coupons')->where('id', $a['id'])->get()->getRow()->status);

        $rep = $this->json($this->asStaff($this->maker(), 'get', 'v1/coupon-report'))['data'];
        $this->assertSame(0, $rep['totals']['committed']);
        $this->assertSame([], $rep['by_coupon']);
    }
}

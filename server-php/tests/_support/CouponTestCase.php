<?php

namespace Tests\Support;

use CodeIgniter\Test\TestResponse;

/**
 * Base for coupon tests (docs/COUPONS.md): published offers (inclusive and exclusive), coupons created by one sales
 * manager and approved (activated) by an administrator, quotes, and a Manage key of scope `quote`.
 */
abstract class CouponTestCase extends LicensingTestCase
{
    public const QUOTE_KEY_ID = 'manage-quote-2026-10';
    public const QUOTE_SECRET = 'manage-quote-secret-0123456789abcdefghijklmno';

    protected function maker(): string
    {
        return $this->staff(['sales_manager'], 'maker@aicountly.org');
    }

    protected function checker(): string
    {
        return $this->staff(['super_admin'], 'checker@aicountly.org');
    }

    /** Engage configured for Manage with read, issue and quote keys. */
    protected function configureManageAll(): void
    {
        $this->configureManage(true);
        $this->setEnv('ENGAGE_INBOUND_MANAGE_KEY_IDS', self::READ_KEY_ID . ',' . self::ISSUE_KEY_ID . ',' . self::QUOTE_KEY_ID);
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_QUOTE_2026_10', self::QUOTE_SECRET);
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SCOPE_MANAGE_QUOTE_2026_10', 'quote');
    }

    protected function quoteCall(string $path, array $body, array $override = [], ?string $keyId = null, ?string $secret = null): TestResponse
    {
        return $this->s2s('POST', $path, '', $override, json_encode($body), $keyId ?? self::QUOTE_KEY_ID, $secret ?? self::QUOTE_SECRET);
    }

    /** Offers used by the coupon tests: books-annual (₹11,800 incl. 18 %) and books-pro (₹10,000 excl. 18 %). */
    protected function offers(): void
    {
        if (! $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->where('status', 'published')->countAllResults()) {
            $this->publishedOffer('books-annual');
        }
        if (! $this->db()->table('engage_offers')->where('offer_code', 'books-pro')->where('status', 'published')->countAllResults()) {
            $this->publishedOffer('books-pro', ['label' => 'Books — Pro', 'price_paise' => 1000000, 'price_basis' => 'exclusive']);
        }
    }

    /** Create a draft as the maker; returns the detail. */
    protected function draft(array $over = []): array
    {
        $r = $this->asStaff($this->maker(), 'post', 'v1/coupons', array_merge([
            'code_kind' => 'shared', 'label' => 'Diwali 20 %', 'discount_type' => 'percent', 'percent_bp' => 2000, 'code' => 'diwali-26',
        ], $over));
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());

        return $this->json($r)['data'];
    }

    /** Draft + approval (activation by the checker). */
    protected function activeCoupon(array $over = [], ?int $bulk = null): array
    {
        $c = $this->draft($over);
        if ($bulk !== null) {
            $g = $this->asStaff($this->maker(), 'post', "v1/coupons/{$c['id']}/codes", ['count' => $bulk]);
            $this->assertSame(201, $this->code($g), (string) $g->response()->getBody());
        }
        $a = $this->asStaff($this->checker(), 'post', "v1/coupons/{$c['id']}/activate");
        $this->assertSame(200, $this->code($a), (string) $a->response()->getBody());

        return $this->json($a)['data'];
    }

    protected function quote(array $body, ?string $token = null): TestResponse
    {
        return $this->asStaff($token ?? $this->maker(), 'post', 'v1/quotes', $body);
    }

    /** @return list<string> */
    protected function codesOf(int $couponId): array
    {
        return array_column($this->db()->table('engage_coupon_codes')->where('coupon_id', $couponId)->orderBy('id')->get()->getResultArray(), 'code');
    }
}

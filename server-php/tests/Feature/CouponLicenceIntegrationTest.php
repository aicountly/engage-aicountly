<?php

namespace Tests\Feature;

use Tests\Support\CouponTestCase;

/**
 * CouponLicenceHook inside a REAL licence write (docs/COUPONS.md §6): issue / renew with quote_ref commit the reserved
 * redemption in the licence transaction; a refused quote rolls the licence back (LicenceHooks → CouponLicenceHook,
 * wired at integration).
 */
final class CouponLicenceIntegrationTest extends CouponTestCase
{
    public function testIssueWithQuoteCommitsTheRedemptionAndRefusedQuotesRollBack(): void
    {
        $this->offers();
        $c = $this->activeCoupon(['max_redemptions_total' => 2]);
        $acc = $this->account();
        $this->mapCompany($acc, 42);
        $q = $this->json($this->quote(['cmp_id' => 42, 'offer_code' => 'books-annual', 'coupon_code' => 'DIWALI26']))['data'];

        // A quote for another offer refuses the licence: nothing is issued, the reservation stays.
        $oq = $this->quote(["cmp_id" => 42, "offer_code" => "books-pro", "coupon_code" => "DIWALI26"]);
        $this->assertSame(201, $this->code($oq), (string) $oq->response()->getBody());
        $other = $this->json($oq)["data"];
        $before = $this->db()->table('engage_licences')->countAllResults();
        $bad = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'cmp_id' => 42, 'offer_code' => 'books-annual', 'quote_ref' => $other['quote_ref']]);
        $this->assertSame(422, $this->code($bad), (string) $bad->response()->getBody());
        $this->assertSame('quote_invalid', $this->json($bad)['code']);
        $this->assertSame('quote_mismatch', $this->json($bad)['details']['reason']);
        $this->assertSame($before, $this->db()->table('engage_licences')->countAllResults(), 'licence rolled back');
        $this->assertSame('reserved', $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $other['quote_ref'])->get()->getRow()->state);

        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'cmp_id' => 42, 'offer_code' => 'books-annual',
            'quote_ref' => $q['quote_ref'], 'invoice_refs' => 'AIC/26-27/000123']);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $lic = $this->json($r)['data'];
        $red = $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $q['quote_ref'])->get()->getRowArray();
        $this->assertSame('committed', $red['state']);
        $this->assertSame($lic['licence_ref'], $red['licence_ref']);
        $this->assertSame('AIC/26-27/000123', $red['finance_document_ref']);
        $this->assertSame('used', $this->db()->table('engage_quotes')->where('quote_ref', $q['quote_ref'])->get()->getRow()->status);

        // The same quote cannot be used for a second licence.
        $again = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $acc, 'cmp_id' => 42, 'offer_code' => 'books-annual',
            'quote_ref' => $q['quote_ref'], 'is_addon' => true]);
        $this->assertSame(422, $this->code($again), (string) $again->response()->getBody());
        $this->assertSame('quote_used', $this->json($again)['details']['reason']);

        // Renew with a renewal quote.
        $rq = $this->json($this->quote(['cmp_id' => 42, 'offer_code' => 'books-annual', 'kind' => 'renewal']))['data'];
        $ren = $this->asStaff($this->admin(), 'post', "v1/licences/{$lic['licence_ref']}/renew", ['version' => $lic['version'], 'quote_ref' => $rq['quote_ref']]);
        $this->assertSame(200, $this->code($ren), (string) $ren->response()->getBody());
        $this->assertSame($lic['licence_ref'], $this->db()->table('engage_quotes')->where('quote_ref', $rq['quote_ref'])->get()->getRow()->licence_ref);
        $this->assertSame((string) $c['id'], (string) $red['coupon_id']);
    }
}

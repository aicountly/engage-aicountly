<?php

namespace Tests\Unit;

use App\Services\Coupons\CodeGenerator;
use App\Services\Coupons\PricingService as P;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Coupon pricing math (docs/COUPONS.md §4) and code generation (§3). Integer paise only; GST on the net (D-C1) with
 * Finance's GstCalculator rule (round half up per line).
 */
final class CouponPricingTest extends CIUnitTestCase
{
    private static function pct(int $bp, ?int $max = null): array
    {
        return ['discount_type' => 'percent', 'percent_bp' => $bp, 'flat_paise' => null, 'max_discount_paise' => $max];
    }

    private static function flat(int $paise): array
    {
        return ['discount_type' => 'flat', 'percent_bp' => null, 'flat_paise' => $paise, 'max_discount_paise' => null];
    }

    public function testRateParsing(): void
    {
        $this->assertSame(1800, P::rateBp('18'));
        $this->assertSame(1800, P::rateBp('18.00'));
        $this->assertSame(1250, P::rateBp('12.5'));
        $this->assertSame(0, P::rateBp('0'));
        $this->assertSame(25, P::rateBp('0.25'));
        $this->assertNull(P::rateBp('100'));
        $this->assertNull(P::rateBp('18.005'));
        $this->assertNull(P::rateBp('-5'));
        $this->assertSame('18', P::trimRate('18.00'));
        $this->assertSame('12.5', P::trimRate('12.50'));
    }

    public function testPercentDiscountIsFlooredAndCapped(): void
    {
        // 15 % of ₹999.99 = 14999.85 paise → floor 14999
        $this->assertSame(14999, P::discount(99999, self::pct(1500)));
        // 50 % of ₹10,000 capped at ₹3,000
        $this->assertSame(300000, P::discount(1000000, self::pct(5000, 300000)));
        // under the cap: the cap does not raise it
        $this->assertSame(100000, P::discount(1000000, self::pct(1000, 300000)));
        // 12.5 %
        $this->assertSame(125000, P::discount(1000000, self::pct(1250)));
    }

    public function testNetNeverBelowOneRupee(): void
    {
        $this->assertSame(49900, P::discount(50000, self::flat(60000)), 'flat ₹600 on ₹500 → ₹499 off, net ₹1');
        $this->assertSame(1000000 - 100, P::discount(1000000, self::pct(10000)), '100 % → net ₹1, never free');
        $this->assertSame(0, P::discount(100, self::flat(50)), 'a ₹1 price cannot be discounted');
        $this->assertSame(0, P::discount(0, self::pct(5000)));
        $q = P::quote(50000, '18', 'exclusive', self::flat(60000));
        $this->assertSame(100, $q['net_paise']);
        $this->assertSame(18, $q['gst_paise']);
    }

    public function testExclusiveBasisGstOnNet(): void
    {
        // Worked example 1: ₹10,000 + GST 18 %, 20 % off → net ₹8,000, GST ₹1,440, total ₹9,440.
        $q = P::quote(1000000, '18.00', 'exclusive', self::pct(2000));
        $this->assertSame(1000000, $q['list_paise']);
        $this->assertSame(200000, $q['discount_paise']);
        $this->assertSame(800000, $q['net_paise']);
        $this->assertSame(800000, $q['taxable_paise']);
        $this->assertSame(144000, $q['gst_paise']);
        $this->assertSame(944000, $q['total_paise']);
        $this->assertSame('18', $q['gst_rate']);
        $this->assertSame(180000, $q['list_gst_paise']);
        $this->assertSame(1180000, $q['list_total_paise']);
    }

    public function testInclusiveBasisGstOnNet(): void
    {
        // Worked example 2: ₹11,800 incl. GST 18 %, 20 % off → pays ₹9,440 of which GST ₹1,440, taxable ₹8,000.
        $q = P::quote(1180000, '18', 'inclusive', self::pct(2000));
        $this->assertSame(236000, $q['discount_paise']);
        $this->assertSame(944000, $q['net_paise']);
        $this->assertSame(144000, $q['gst_paise']);
        $this->assertSame(800000, $q['taxable_paise']);
        $this->assertSame(944000, $q['total_paise'], 'inclusive: the total is the net');

        // Worked example 3: ₹500 off ₹11,800 incl. 18 % → ₹11,300; GST = round(1130000 × 1800 / 11800) = 172373 (half up)
        $q = P::quote(1180000, '18', 'inclusive', self::flat(50000));
        $this->assertSame(1130000, $q['net_paise']);
        $this->assertSame(172373, $q['gst_paise']);
        $this->assertSame(957627, $q['taxable_paise']);
        $this->assertSame(1130000, $q['total_paise']);
    }

    public function testRoundingHalfUpMatchesFinance(): void
    {
        // exclusive ₹0.25 at 18 % → 4.5 paise → 5
        $this->assertSame(['taxable' => 25, 'gst' => 5, 'total' => 30], P::gst(25, 1800, 'exclusive'));
        // exclusive ₹0.36 at 12.5 % → 4.5 → 5
        $this->assertSame(5, P::gst(36, 1250, 'exclusive')['gst']);
        // inclusive 59 paise at 18 % → 59 × 1800 / 11800 = 9.0 → 9
        $this->assertSame(['taxable' => 50, 'gst' => 9, 'total' => 59], P::gst(59, 1800, 'inclusive'));
        // zero rate
        $this->assertSame(['taxable' => 12345, 'gst' => 0, 'total' => 12345], P::gst(12345, 0, 'inclusive'));
        // without a coupon nothing changes
        $q = P::quote(1180000, '18', 'inclusive');
        $this->assertSame(0, $q['discount_paise']);
        $this->assertSame(180000, $q['gst_paise']);
    }

    public function testInvalidInputs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        P::quote(1000, '18', 'gross');
    }

    // ------------------------------------------------------------------ codes

    public function testNormaliseAndShapes(): void
    {
        $this->assertSame('DIWALI26', CodeGenerator::normalise(' diwali-26 '));
        $this->assertSame('ABCDEFGH', CodeGenerator::normalise('abcd efgh'));
        $this->assertTrue(CodeGenerator::isValidShared('DIWALI26'));
        $this->assertFalse(CodeGenerator::isValidShared('AB1'));
        $this->assertFalse(CodeGenerator::isValidShared(str_repeat('A', 21)));
        $this->assertTrue(CodeGenerator::isValidPrefix('AXE'));
        $this->assertFalse(CodeGenerator::isValidPrefix('AIC'), 'I is not in the alphabet');
        $this->assertFalse(CodeGenerator::isValidPrefix('AI0'), '0 is not in the alphabet');
        $this->assertFalse(CodeGenerator::isValidPrefix('ABCDEFGHJ'), 'at most 8');
        $this->assertSame('ABCD-EFGH-JK', CodeGenerator::display('ABCDEFGHJK'));
    }

    public function testSingleUseCodesCarryValidCheckCharacters(): void
    {
        $seen = [];
        for ($i = 0; $i < 300; $i++) {
            $c = CodeGenerator::singleUse('AXE');
            $this->assertMatchesRegularExpression('/\AAXE[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}\z/', $c);
            $this->assertTrue(CodeGenerator::hasValidCheck($c, 'AXE'));
            $seen[$c] = true;
        }
        $this->assertCount(300, $seen, 'random part is not repeating');
        $this->assertSame(0, preg_match('/[01OI]/', implode('', array_keys($seen))), 'no 0/O/1/I');
    }

    public function testCheckCharactersCatchTyposAndSwaps(): void
    {
        $alpha = CodeGenerator::ALPHABET;
        for ($n = 0; $n < 50; $n++) {
            $c = CodeGenerator::singleUse();
            // every single substitution in the body is detected
            for ($i = 0; $i < 10; $i++) {
                $other = $alpha[(strpos($alpha, $c[$i]) + 1 + random_int(0, 30)) % 32];
                $bad = substr_replace($c, $other, $i, 1);
                $this->assertFalse(CodeGenerator::hasValidCheck($bad), "substitution at {$i} of {$c}");
            }
            // every adjacent transposition of two different characters is detected
            for ($i = 0; $i < 9; $i++) {
                if ($c[$i] === $c[$i + 1]) {
                    continue;
                }
                $bad = substr($c, 0, $i) . $c[$i + 1] . $c[$i] . substr($c, $i + 2);
                $this->assertFalse(CodeGenerator::hasValidCheck($bad), "swap at {$i} of {$c}");
            }
        }
        $this->assertFalse(CodeGenerator::hasValidCheck('SHORT'));
    }

    public function testAicS2sQuoteScope(): void
    {
        $c = \App\Libraries\AicS2s\AicS2sConfig::class;
        $this->assertSame(['POST'], $c::methodsFor('quote'));
        $this->assertSame(['GET', 'HEAD'], $c::methodsFor('read'));
        $this->assertSame(['POST'], $c::methodsFor('issue'));
        $this->assertContains('quote', $c::SCOPES);
        $this->assertSame(60, $c::DEFAULT_RATE_LIMITS['quote']);
        $this->assertSame([], $c::methodsFor('unknown'));
    }

    public function testFingerprintNeverStoresTheCode(): void
    {
        $f = CodeGenerator::fingerprint('DIWALI26');
        $this->assertSame(64, strlen($f));
        $this->assertStringNotContainsString('DIWALI', $f);
    }
}

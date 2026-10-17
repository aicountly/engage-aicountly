<?php

namespace Tests\Unit;

use App\Libraries\ConsoleRoleSync;
use App\Libraries\Seller\CommissionRules;
use App\Libraries\Seller\IndianTaxIds;
use App\Libraries\Seller\Money;
use App\Libraries\Seller\SellerCrypto;
use App\Services\Seller\SellerAccountService;
use CodeIgniter\Test\CIUnitTestCase;

final class SellerLibrariesTest extends CIUnitTestCase
{
    public function testMoneyIsExactPaise(): void
    {
        $this->assertSame(123450, Money::toPaise('1234.5'));
        $this->assertSame(100, Money::toPaise(1));
        $this->assertSame('1234.50', Money::format(123450));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame(75000, Money::percentE4('7.5'));
        $this->assertSame(1, Money::applyPercent(10, 75000), '0.75 paise rounds half-up to 1');
        $this->assertSame(100000, Money::applyPercent(1000000, 100000));
        foreach (['-1', '1.234', 'abc', '', null] as $bad) {
            try {
                Money::toPaise($bad);
                $this->fail('accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Money::percentE4('100.0001');
    }

    public function testCommissionRuleValidation(): void
    {
        [, $e] = CommissionRules::normalise(['type' => 'bogus']);
        $this->assertArrayHasKey('rules.type', $e);
        [, $e] = CommissionRules::normalise(['type' => 'percent_recurring_months', 'percent' => '5', 'months' => 0]);
        $this->assertArrayHasKey('rules', $e);
        [, $e] = CommissionRules::normalise(['type' => 'slab', 'slabs' => [['from' => '0', 'to' => '100', 'percent' => '5'], ['from' => '50', 'to' => null, 'percent' => '6']]]);
        $this->assertStringContainsString('overlap', $e['rules']);
        [, $e] = CommissionRules::normalise(['type' => 'slab', 'slabs' => [['from' => '0', 'to' => null, 'percent' => '5', 'amount' => '1']]]);
        $this->assertArrayHasKey('rules', $e);
        [$n, $e] = CommissionRules::normalise(['type' => 'flat_per_conversion', 'amount' => '2500']);
        $this->assertSame([], $e);
        $this->assertSame('2500.00', $n['amount']);
    }

    public function testCommissionCalculation(): void
    {
        [$first] = CommissionRules::normalise(['type' => 'percent_of_first_payment', 'percent' => '10']);
        $this->assertSame(120000, CommissionRules::calculate($first, 1, 1200000)['amount_paise']);
        $this->assertNull(CommissionRules::calculate($first, 2, 1200000));

        [$rec] = CommissionRules::normalise(['type' => 'percent_recurring_months', 'percent' => '2.5', 'months' => 3]);
        $this->assertSame(2500, CommissionRules::calculate($rec, 3, 100000)['amount_paise']);
        $this->assertNull(CommissionRules::calculate($rec, 4, 100000));

        [$flat] = CommissionRules::normalise(['type' => 'flat_per_conversion', 'amount' => '999.99']);
        $this->assertSame(99999, CommissionRules::calculate($flat, 1, 5)['amount_paise']);
        $this->assertNull(CommissionRules::calculate($flat, 2, 5));

        [$slab] = CommissionRules::normalise(['type' => 'slab', 'slabs' => [
            ['from' => '50000', 'to' => null, 'amount' => '6000'], ['from' => '0', 'to' => '50000', 'percent' => '8'],
        ]]);
        $this->assertSame('0.00', $slab['slabs'][0]['from'], 'slabs are sorted');
        $this->assertSame(320000, CommissionRules::calculate($slab, 1, 4000000)['amount_paise']);
        $this->assertSame(600000, CommissionRules::calculate($slab, 1, 5000000)['amount_paise'], 'to is exclusive');
        $this->assertNull(CommissionRules::calculate($slab, 2, 5000000));

        $this->assertTrue(CommissionRules::scopesOverlap(['*'], ['books']));
        $this->assertFalse(CommissionRules::scopesOverlap(['books'], ['fr']));
        $this->assertTrue(CommissionRules::scopeCovers(['*'], null));
        $this->assertFalse(CommissionRules::scopeCovers(['books'], null));
    }

    public function testIndianIds(): void
    {
        $this->assertTrue(IndianTaxIds::isPan('ABCPE1234F'));
        $this->assertFalse(IndianTaxIds::isPan('ABCPE1234'));
        $this->assertNull(IndianTaxIds::panEntityMismatch('ABCPE1234F', 'individual'));
        $this->assertNotNull(IndianTaxIds::panEntityMismatch('ABCPE1234F', 'company'));
        $this->assertNull(IndianTaxIds::panEntityMismatch('AAPFU0939F', 'llp'));
        foreach (['27AAPFU0939F1ZV', '29AAGCB7383J1Z4', '07AAACI1681G1ZR'] as $g) {
            $this->assertTrue(IndianTaxIds::isGstin($g), $g);
        }
        $this->assertFalse(IndianTaxIds::isGstin('27AAPFU0939F1ZW'), 'wrong check character');
        $this->assertFalse(IndianTaxIds::isGstin('40AAPFU0939F1ZV'), 'no state 40');
        $this->assertTrue(IndianTaxIds::isIfsc('HDFC0001234'));
        $this->assertFalse(IndianTaxIds::isIfsc('HDFC1001234'));
        $this->assertTrue(IndianTaxIds::isBankAccount('123456789'));
        $this->assertFalse(IndianTaxIds::isBankAccount('12345678'));
        $this->assertTrue(IndianTaxIds::isPincode('411001'));
        $this->assertFalse(IndianTaxIds::isPincode('011001'));
    }

    public function testCryptoRoundTripRotationAndMasking(): void
    {
        $k1 = bin2hex(random_bytes(32));
        $k2 = base64_encode(random_bytes(32));
        $old = new SellerCrypto($k1, '');
        $c = $old->encrypt('ABCPE1234F');
        $this->assertStringStartsWith('v1:', $c);
        $this->assertNotSame($c, $old->encrypt('ABCPE1234F'), 'random nonce');
        $this->assertSame('ABCPE1234F', $old->decrypt($c));
        $rotated = new SellerCrypto($k2, $k1);
        $this->assertSame('ABCPE1234F', $rotated->decrypt($c), 'previous key still decrypts');
        $this->assertFalse((new SellerCrypto('too-short', ''))->isConfigured());
        $this->assertSame('••••1234', SellerCrypto::mask('50101234'));
        $this->assertSame('••••••1234', SellerCrypto::maskLast4('1234'));
        $this->expectException(\RuntimeException::class);
        (new SellerCrypto($k2, ''))->decrypt($c);
    }

    public function testPasswordPolicyAndMasks(): void
    {
        $this->assertNotNull(SellerAccountService::passwordProblem('short1'));
        $this->assertNotNull(SellerAccountService::passwordProblem('onlyletters'));
        $this->assertNotNull(SellerAccountService::passwordProblem('1234567890'));
        $this->assertNotNull(SellerAccountService::passwordProblem('Ravi12345@x.com', 'ravi12345@x.com'));
        $this->assertNull(SellerAccountService::passwordProblem('Str0ngPassword'));
        $this->assertSame('r***@example.com', SellerAccountService::maskEmail('ravi@example.com'));
        $this->assertSame('+91XXXXXX3210', SellerAccountService::maskMobile('+91 98765 43210'));
    }

    public function testRoleCatalogFollowsTheContract(): void
    {
        $this->assertSame(['super_admin', 'sales_manager', 'seller_manager', 'kyc_reviewer', 'member'], ConsoleRoleSync::CATALOG);
        $this->assertSame(['seller_manager', 'kyc_reviewer'], ConsoleRoleSync::normaliseRoles(['KYC_Reviewer', 'seller_manager', 'finance_admin'], false));
    }
}

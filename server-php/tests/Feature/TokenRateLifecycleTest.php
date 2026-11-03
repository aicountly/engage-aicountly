<?php

namespace Tests\Feature;

use Tests\Support\LicensingTestCase;

/**
 * Token conversion rates end to end: the rights, the four eyes, the immutability and the
 * one-live-rate rule.
 *
 * A token rate decides what a customer is charged for every AI call, so it is held to the same
 * standard as a coupon: one person cannot both write and approve it, an approved rate can never
 * be edited, and two rates can never both be live for the same model.
 */
final class TokenRateLifecycleTest extends LicensingTestCase
{
    private function maker(): string
    {
        return $this->staff(['sales_manager'], 'rate-maker@aicountly.org');
    }

    private function checker(): string
    {
        return $this->staff(['super_admin'], 'rate-checker@aicountly.org');
    }

    private function draft(string $token, array $over = []): array
    {
        $res = $this->asStaff($token, 'post', 'v1/token-rates', $over + [
            'provider_code' => 'anthropic',
            'model_key'     => 'claude-sonnet-5',
            'multiplier'    => 10,
            'label'         => 'Sonnet 5 — general purpose',
        ]);
        $res->assertStatus(201);

        return json_decode($res->getJSON(), true)['data'];
    }

    public function testADraftIsCreatedInBasisPointsAndReadsBackAsAMultiplier(): void
    {
        $rate = $this->draft($this->maker());

        $this->assertSame('draft', $rate['status'], 'nothing is live until a second person approves it');
        $this->assertSame(100000, $rate['multiplier_bp'], '10x is 100000 basis points — never a float');
        $this->assertSame('10x', $rate['multiplier']);
        $this->assertStringStartsWith('TKR-', $rate['rate_ref']);
    }

    public function testAMultiplierCanBeSentAsBasisPointsToo(): void
    {
        $rate = $this->draft($this->maker(), ['multiplier' => '', 'multiplier_bp' => 250000]);

        $this->assertSame('25x', $rate['multiplier']);
    }

    public function testTheAuthorCannotApproveTheirOwnRate(): void
    {
        $maker = $this->maker();
        $rate = $this->draft($maker);

        $res = $this->asStaff($maker, 'post', "v1/token-rates/{$rate['id']}/activate");

        $res->assertStatus(409);
        $this->assertSame('four_eyes', json_decode($res->getJSON(), true)['code']);
    }

    public function testSomeoneWhoEditedTheDraftCannotApproveItEither(): void
    {
        $maker = $this->maker();
        $second = $this->staff(['sales_manager'], 'rate-editor@aicountly.org');
        $rate = $this->draft($maker);

        $this->asStaff($second, 'put', "v1/token-rates/{$rate['id']}", [
            'provider_code' => 'anthropic', 'model_key' => 'claude-sonnet-5',
            'multiplier' => 12, 'label' => 'Sonnet 5 — general purpose',
        ])->assertStatus(200);

        $res = $this->asStaff($second, 'post', "v1/token-rates/{$rate['id']}/activate");

        $res->assertStatus(409);
        $this->assertSame('four_eyes', json_decode($res->getJSON(), true)['code'],
            'editing a colleague\'s draft and then approving it is approving your own rule');
    }

    public function testASecondPersonActivatesIt(): void
    {
        $rate = $this->draft($this->maker());

        $res = $this->asStaff($this->checker(), 'post', "v1/token-rates/{$rate['id']}/activate");

        $res->assertStatus(200);
        $this->assertSame('active', json_decode($res->getJSON(), true)['data']['status']);
    }

    public function testAnActiveRateCannotBeEdited(): void
    {
        $rate = $this->draft($this->maker());
        $this->asStaff($this->checker(), 'post', "v1/token-rates/{$rate['id']}/activate")->assertStatus(200);

        $res = $this->asStaff($this->checker(), 'put', "v1/token-rates/{$rate['id']}", [
            'provider_code' => 'anthropic', 'model_key' => 'claude-sonnet-5',
            'multiplier' => 1, 'label' => 'Suddenly much cheaper',
        ]);

        $res->assertStatus(409);
        $this->assertSame('not_draft', json_decode($res->getJSON(), true)['code'],
            'a rate that has priced real usage is evidence: retire it and add a new one');
    }

    public function testTwoRatesCannotBeLiveForTheSameModel(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $first = $this->draft($maker);
        $this->asStaff($checker, 'post', "v1/token-rates/{$first['id']}/activate")->assertStatus(200);

        $second = $this->draft($maker, ['multiplier' => 25, 'label' => 'Sonnet 5 — dearer']);
        $res = $this->asStaff($checker, 'post', "v1/token-rates/{$second['id']}/activate");

        $res->assertStatus(409);
        $body = json_decode($res->getJSON(), true);
        $this->assertSame('rate_exists', $body['code']);
        $this->assertSame($first['rate_ref'], $body['details']['rate_ref'], 'the refusal names the rate in the way');
    }

    public function testRetiringFreesTheModelForANewRate(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $first = $this->draft($maker);
        $this->asStaff($checker, 'post', "v1/token-rates/{$first['id']}/activate")->assertStatus(200);

        $this->asStaff($checker, 'post', "v1/token-rates/{$first['id']}/retire", ['reason' => 'Vendor raised the price'])
            ->assertStatus(200);

        $second = $this->draft($maker, ['multiplier' => 25, 'label' => 'Sonnet 5 — dearer']);
        $this->asStaff($checker, 'post', "v1/token-rates/{$second['id']}/activate")->assertStatus(200);

        $active = json_decode($this->asStaff($checker, 'get', 'v1/token-rates', ['status' => 'active'])->getJSON(), true)['data']['items'];
        $this->assertCount(1, $active);
        $this->assertSame('25x', $active[0]['multiplier']);
    }

    public function testRetiringNeedsAReason(): void
    {
        $rate = $this->draft($this->maker());

        $this->asStaff($this->checker(), 'post', "v1/token-rates/{$rate['id']}/retire", ['reason' => ''])
            ->assertStatus(422);
    }

    public function testAnAbsurdMultiplierIsRefused(): void
    {
        $this->asStaff($this->maker(), 'post', 'v1/token-rates', [
            'provider_code' => 'anthropic', 'model_key' => 'x', 'multiplier' => 5000, 'label' => 'Typo',
        ])->assertStatus(422);
    }

    public function testAReadOnlyRoleCannotCreateARate(): void
    {
        $viewer = $this->staff(['seller_manager'], 'rate-viewer@aicountly.org');

        $this->asStaff($viewer, 'get', 'v1/token-rates')->assertStatus(200);
        $this->asStaff($viewer, 'post', 'v1/token-rates', [
            'provider_code' => 'anthropic', 'model_key' => 'y', 'multiplier' => 1, 'label' => 'Nope',
        ])->assertStatus(403);
    }

    public function testPricingDefaultsToOneRupeePerThousandTokensExclusiveOfGst(): void
    {
        $res = $this->asStaff($this->maker(), 'get', 'v1/token-rates/pricing');

        $res->assertStatus(200);
        $d = json_decode($res->getJSON(), true)['data'];
        $this->assertSame(100, $d['pricing']['paise_per_1000_tokens'], 'Rs 1.00 per 1,000 tokens');
        $this->assertSame('exclusive', $d['pricing']['price_basis'], 'GST is added on top');
        $this->assertSame(100000, $d['example']['taxable_paise'], '1,000,000 tokens = Rs 1,000 before GST');
        $this->assertEqualsCanonicalizing(['low' => 10000, 'medium' => 100000, 'high' => 250000], $d['tier_fallback']);
    }

    public function testPricingCanBeChangedAndAnInvalidSacIsRefused(): void
    {
        $admin = $this->staff(['super_admin'], 'rate-pricer@aicountly.org');

        $this->asStaff($admin, 'put', 'v1/token-rates/pricing', ['sac' => '1234'])->assertStatus(422);

        $res = $this->asStaff($admin, 'put', 'v1/token-rates/pricing', ['paise_per_1000_tokens' => 200]);
        $res->assertStatus(200);
        $this->assertSame(200, json_decode($res->getJSON(), true)['data']['pricing']['paise_per_1000_tokens']);
    }

    /** Console being down must not take the rate card down with it. */
    public function testTheCatalogueStillAnswersWhenConsoleIsUnreachable(): void
    {
        $res = $this->asStaff($this->maker(), 'get', 'v1/token-rates/catalogue');

        $res->assertStatus(200);
        $d = json_decode($res->getJSON(), true)['data'];
        $this->assertFalse($d['catalogue_available'], 'Console is not configured in tests');
        $this->assertNotNull($d['catalogue_error'], 'and it says why, rather than showing an empty catalogue');
        $this->assertSame(100, $d['pricing']['paise_per_1000_tokens'], 'the rates are Engage\'s own and stay readable');
    }
}

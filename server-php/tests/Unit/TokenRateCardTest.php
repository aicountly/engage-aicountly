<?php

namespace Tests\Unit;

use App\Services\Tokens\TokenRateCard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The arithmetic that decides what a customer is charged. Pure: no database, no settings.
 *
 * Every number here is one a customer could be shown, so each case says what it means in
 * rupees or tokens rather than only asserting an integer.
 */
final class TokenRateCardTest extends CIUnitTestCase
{
    private const TIERS = ['low' => 10000, 'medium' => 100000, 'high' => 250000];

    private function card(array $rates = []): TokenRateCard
    {
        return TokenRateCard::fromRows($rates, self::TIERS);
    }

    public function testAModelRateBeatsAProviderRateWhichBeatsTheTier(): void
    {
        $card = $this->card([
            ['provider_code' => 'anthropic', 'model_key' => 'claude-sonnet-5', 'multiplier_bp' => 100000],
            ['provider_code' => 'anthropic', 'model_key' => null, 'multiplier_bp' => 250000],
        ]);

        $this->assertSame(100000, $card->resolve('anthropic', 'claude-sonnet-5', 'high')['multiplier_bp'], 'the model rate wins');
        $this->assertSame('model', $card->resolve('anthropic', 'claude-sonnet-5', 'high')['source']);

        $other = $card->resolve('anthropic', 'claude-opus-5', 'low');
        $this->assertSame(250000, $other['multiplier_bp'], "the provider's own default covers its other models");
        $this->assertSame('provider', $other['source'], 'and it beats the tier, even a cheaper one');
    }

    public function testAModelWithNoRateFallsBackToItsConsoleTierAndWarns(): void
    {
        $r = $this->card()->resolve('google', 'gemini-3-pro', 'high');

        $this->assertSame(250000, $r['multiplier_bp'], 'high tier is 25x');
        $this->assertSame('tier:high', $r['source']);
        $this->assertNotNull($r['warning'], 'a model nobody has priced is never charged silently');
        $this->assertStringContainsString('gemini-3-pro', $r['warning']);
    }

    public function testAnUnclassifiedModelIsChargedAtMediumAndWarns(): void
    {
        $r = $this->card()->resolve('newvendor', 'mystery-1', null);

        $this->assertSame(100000, $r['multiplier_bp'], 'medium, not free and not a refusal');
        $this->assertSame('unclassified', $r['source']);
        $this->assertNotNull($r['warning']);
    }

    /** Free would make an unpriced model the cheapest way to use AI. */
    public function testAnUnknownModelIsNeverFree(): void
    {
        $this->assertGreaterThan(0, $this->card()->resolve('who', 'what', null)['multiplier_bp']);
    }

    public function testProviderNameIsMatchedWithoutRegardToCase(): void
    {
        $card = $this->card([['provider_code' => 'OpenAI', 'model_key' => null, 'multiplier_bp' => 10000]]);

        $this->assertSame('provider', $card->resolve('openai', 'gpt-6-luna', 'high')['source']);
    }

    public function testBillableIsTheFlatSumWeightedOnce(): void
    {
        $card = $this->card();

        // 1,000 in + 500 out on a 10x model = 15,000 billable tokens.
        $this->assertSame(15000, $card->billable(1000, 500, 100000));
        // The same tokens on a 1x model cost what they are.
        $this->assertSame(1500, $card->billable(1000, 500, 10000));
        $this->assertSame(0, $card->billable(0, 0, 250000));
        $this->assertSame(0, $card->billable(1000, 500, 0), 'a 0x rate means free on this model');
    }

    /**
     * The flat model's point: the charge must not depend on how the tokens split between input
     * and output, or a customer could be charged differently for identical total usage.
     */
    public function testTheInputOutputSplitDoesNotChangeTheCharge(): void
    {
        $card = $this->card();

        $this->assertSame(
            $card->billable(1500, 0, 100000),
            $card->billable(500, 1000, 100000),
        );
    }

    /** Per-call charges must sum to the charge on the summed tokens, or a bill will not foot. */
    public function testChargesAddUpAcrossCalls(): void
    {
        $card = $this->card();
        $calls = [[100, 50], [37, 13], [1, 1], [9999, 1]];

        $perCall = 0;
        $inTotal = 0;
        $outTotal = 0;
        foreach ($calls as [$in, $out]) {
            $perCall += $card->billable($in, $out, 10000);
            $inTotal += $in;
            $outTotal += $out;
        }

        $this->assertSame($card->billable($inTotal, $outTotal, 10000), $perCall, 'at 1x, the parts equal the whole');
    }

    public function testNegativeTokensCannotCreditAnAccount(): void
    {
        $this->assertSame(0, $this->card()->billable(-1000, -1000, 100000));
    }

    public function testRoundingIsHalfUpOnTheFinalFigure(): void
    {
        $card = $this->card();

        $this->assertSame(2, $card->billable(1, 0, 15000), '1 token at 1.5x rounds to 2');
        $this->assertSame(3, $card->billable(2, 0, 15000), '2 tokens at 1.5x is exactly 3');
    }

    public function testDescribeReadsTheWayStaffWroteIt(): void
    {
        $this->assertSame('1x', TokenRateCard::describe(10000));
        $this->assertSame('10x', TokenRateCard::describe(100000));
        $this->assertSame('25x', TokenRateCard::describe(250000));
        $this->assertSame('1.5x', TokenRateCard::describe(15000));
        $this->assertSame('0x', TokenRateCard::describe(0));
    }
}

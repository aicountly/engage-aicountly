<?php

namespace Tests\Unit;

use App\Services\Tokens\TokenRatePusher;
use App\Services\Tokens\TokenRateService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The card Engage sends Pulse.
 *
 * Everything here is about one risk: the card deciding what a customer is charged must never
 * be quietly WRONG. Missing is recoverable — Pulse keeps the last good card — but wrong is
 * money taken on a rate nobody set.
 */
final class TokenRatePusherTest extends CIUnitTestCase
{
    /** A TokenRateService that answers from fixtures, with no database. */
    private function rates(array $active, array $catalogueModels): TokenRateService
    {
        return new class ($active, $catalogueModels) extends TokenRateService {
            public function __construct(private array $active, private array $models)
            {
                parent::__construct();
            }

            public function activeRates(): array
            {
                return $this->active;
            }

            public function tierFallback(): array
            {
                return ['low' => 10000, 'medium' => 100000, 'high' => 250000];
            }

            public function unclassifiedTier(): string
            {
                return 'medium';
            }

            public function priceCatalogue(array $models): array
            {
                // The real one needs the card from the database; the resolution itself is
                // covered by TokenRateCardTest. Here the fixture states the outcome.
                return ['models' => $this->models, 'warnings' => []];
            }
        };
    }

    private function catalogue(bool $ok, array $models = []): \Closure
    {
        return static fn (): array => ['ok' => $ok, 'models' => $models, 'error' => $ok ? null : 'Console is down'];
    }

    public function testEveryKnownModelIsOnTheCardAtItsResolvedRate(): void
    {
        $pusher = new TokenRatePusher(null, $this->rates([], [
            ['provider_slug' => 'anthropic', 'model_key' => 'claude-sonnet-5', 'multiplier_bp' => 100000],
            ['provider_slug' => 'google', 'model_key' => 'gemini-3-pro', 'multiplier_bp' => 250000],
        ]), $this->catalogue(true, [['x']]));

        $card = $pusher->card();

        $this->assertSame(100000, $card['rates']['anthropic|claude-sonnet-5']);
        $this->assertSame(250000, $card['rates']['google|gemini-3-pro'], 'a model priced only by its tier is still sent: Pulse cannot work a tier out');
        $this->assertSame(2, $card['models']);
    }

    public function testAProviderWideRateTravelsSeparately(): void
    {
        $pusher = new TokenRatePusher(null, $this->rates([
            ['provider_code' => 'anthropic', 'model_key' => null, 'multiplier_bp' => 250000],
        ], []), $this->catalogue(true));

        $card = $pusher->card();

        $this->assertSame(250000, $card['provider_defaults']['anthropic'],
            'so a model Console gains between pushes is priced by its provider, not the estate fallback');
    }

    /**
     * The bug this test exists for: a model rate is Engage's own data, and a Console outage
     * dropping it from the card would silently reprice that model at the provider default.
     */
    public function testAModelRateIsOnTheCardEvenWhenConsoleIsDown(): void
    {
        $pusher = new TokenRatePusher(null, $this->rates([
            ['provider_code' => 'anthropic', 'model_key' => 'claude-sonnet-5', 'multiplier_bp' => 100000],
            ['provider_code' => 'anthropic', 'model_key' => null, 'multiplier_bp' => 250000],
        ], []), $this->catalogue(false));

        $card = $pusher->card();

        $this->assertSame(100000, $card['rates']['anthropic|claude-sonnet-5'],
            'the 10x somebody deliberately set, not the 25x provider default');
        $this->assertFalse($card['catalogue_available']);
    }

    /** Missing is recoverable; wrong is not. */
    public function testAnIncompleteCardIsNotPushedAtAll(): void
    {
        $pusher = new TokenRatePusher(null, $this->rates([], []), $this->catalogue(false));

        $r = $pusher->push();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('keeps the card it already has', $r['message']);
    }

    public function testNothingIsSentWhenPulseIsNotConfigured(): void
    {
        putenv('PULSE_API_BASE_URL=');
        $pusher = new TokenRatePusher(null, $this->rates([], []), $this->catalogue(true));

        $r = $pusher->push();

        $this->assertFalse($r['ok']);
        $this->assertStringStartsWith('Not configured', $r['message'], 'and the command treats this as "nothing to do", not a nightly alert');
    }

    public function testTheFallbackIsTheUnclassifiedTiersRate(): void
    {
        $card = (new TokenRatePusher(null, $this->rates([], []), $this->catalogue(true)))->card();

        $this->assertSame(100000, $card['fallback_bp'], 'medium, 10x — never free');
        $this->assertGreaterThan(0, $card['fallback_bp']);
    }
}

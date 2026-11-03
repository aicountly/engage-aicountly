<?php
declare(strict_types=1);

namespace App\Services\Tokens;

/**
 * The rules that turn provider tokens into billable tokens, as one immutable value.
 *
 *     billable = round( (input + output) x multiplier )
 *
 * This class is the ONLY definition of that arithmetic in the estate. It holds no database
 * handle and reaches nothing: it is built from rows, it is pure, and it is the same object
 * Engage uses to show a rate, that is pushed to Pulse to enforce one, and that a test can
 * construct by hand. If the number Pulse charges ever disagrees with the number Engage shows,
 * it will be because something stopped using this class — not because two copies drifted.
 *
 * Multipliers are integer basis points of 1x (10000 = 1x). Nothing here is a float.
 *
 * Resolution order, most specific first:
 *
 *   1. an active rate for this exact (provider, model)
 *   2. an active rate for this provider with no model (its default)
 *   3. the fallback for the model's Console tier — low 1x, medium 10x, high 25x
 *   4. the fallback for the unclassified tier (medium), which is ALSO a warning
 *
 * Step 4 is deliberately not free and not a refusal. Charging nothing would make an
 * unclassified model the cheapest way to use AI, and refusing would take a product offline
 * because of a missing commercial setting. It is charged at the middle rate and named, loudly,
 * everywhere a human might look.
 */
final class TokenRateCard
{
    public const ONE_X = 10000;

    /** @var array<string, int> "provider|model" and "provider|*" => multiplier_bp */
    private array $rates;

    /** @var array<string, int> tier => multiplier_bp */
    private array $tierFallback;

    public function __construct(
        array $rates,
        array $tierFallback,
        private readonly string $unclassifiedTier = 'medium',
    ) {
        $this->rates = $rates;
        $this->tierFallback = $tierFallback;
    }

    /**
     * @param list<array{provider_code:string, model_key:?string, multiplier_bp:int|string}> $rateRows active rates
     * @param array<string,int|string> $tierFallback e.g. ['low' => 10000, 'medium' => 100000, 'high' => 250000]
     */
    public static function fromRows(array $rateRows, array $tierFallback, string $unclassifiedTier = 'medium'): self
    {
        $rates = [];
        foreach ($rateRows as $r) {
            $rates[self::key((string) $r['provider_code'], $r['model_key'] === null ? null : (string) $r['model_key'])]
                = (int) $r['multiplier_bp'];
        }
        $tiers = [];
        foreach ($tierFallback as $tier => $bp) {
            $tiers[strtolower((string) $tier)] = (int) $bp;
        }

        return new self($rates, $tiers, strtolower($unclassifiedTier));
    }

    private static function key(string $provider, ?string $model): string
    {
        return strtolower($provider) . '|' . ($model === null || $model === '' ? '*' : $model);
    }

    /**
     * The multiplier for one call, and where it came from.
     *
     * `source` is part of the answer rather than a detail, because "what rate was I charged and
     * why" is the first question anyone asks about a bill. Pulse stores it on the ledger entry.
     *
     * @param ?string $tier the model's Console tier: low | medium | high, or null if unclassified
     * @return array{multiplier_bp:int, source:string, warning:?string}
     */
    public function resolve(string $provider, string $model, ?string $tier): array
    {
        $exact = self::key($provider, $model);
        if (isset($this->rates[$exact])) {
            return ['multiplier_bp' => $this->rates[$exact], 'source' => 'model', 'warning' => null];
        }

        $byProvider = self::key($provider, null);
        if (isset($this->rates[$byProvider])) {
            return ['multiplier_bp' => $this->rates[$byProvider], 'source' => 'provider', 'warning' => null];
        }

        $tier = $tier === null || $tier === '' ? null : strtolower($tier);
        if ($tier !== null && isset($this->tierFallback[$tier])) {
            return [
                'multiplier_bp' => $this->tierFallback[$tier],
                'source'        => 'tier:' . $tier,
                'warning'       => "No token rate is set for {$provider}/{$model}; it is charged at the {$tier} tier rate.",
            ];
        }

        // Nobody has priced this model and nobody has classified it either.
        $fallbackTier = $this->unclassifiedTier;
        $bp = $this->tierFallback[$fallbackTier] ?? (self::ONE_X * 10);

        return [
            'multiplier_bp' => $bp,
            'source'        => 'unclassified',
            'warning'       => "{$provider}/{$model} has no token rate and no tier in Console; "
                . "it is charged at the {$fallbackTier} tier rate until someone sets one.",
        ];
    }

    /**
     * Billable tokens for one call.
     *
     * Rounds half up on the final figure only. The founder's decision is a FLAT count — input and
     * output are added first and weighted once — so a call's charge never depends on the
     * input/output split, and the sum of per-call charges equals the charge on the summed tokens.
     */
    public function billable(int $inputTokens, int $outputTokens, int $multiplierBp): int
    {
        $raw = max(0, $inputTokens) + max(0, $outputTokens);
        if ($raw === 0 || $multiplierBp === 0) {
            return 0;
        }

        return intdiv($raw * $multiplierBp + (self::ONE_X >> 1), self::ONE_X);
    }

    /** What a multiplier reads as to a human: 10000 => "1x", 250000 => "25x", 15000 => "1.5x". */
    public static function describe(int $multiplierBp): string
    {
        if ($multiplierBp % self::ONE_X === 0) {
            return intdiv($multiplierBp, self::ONE_X) . 'x';
        }

        return rtrim(rtrim(number_format($multiplierBp / self::ONE_X, 4, '.', ''), '0'), '.') . 'x';
    }

    /** @return array<string,int> the active rates, for the push payload and for tests. */
    public function rates(): array
    {
        return $this->rates;
    }

    /** @return array<string,int> */
    public function tierFallback(): array
    {
        return $this->tierFallback;
    }

    public function unclassifiedTier(): string
    {
        return $this->unclassifiedTier;
    }
}

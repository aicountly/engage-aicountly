<?php
declare(strict_types=1);

namespace App\Libraries\Seller;

use InvalidArgumentException;

/**
 * Commission plan rules (engage_commission_plans.rules) — pure validation and calculation.
 *
 * Rule shapes (money as "1234.50" strings, percent as "7.5" strings, never floats):
 *   {"type":"percent_of_first_payment", "percent":"10"}
 *   {"type":"percent_recurring_months", "percent":"5", "months":12}
 *       — every confirmed payment whose sequence number is ≤ months
 *   {"type":"flat_per_conversion", "amount":"2500.00"}
 *       — once per converted lead, on its first confirmed payment
 *   {"type":"slab", "applies_to":"first_payment"|"recurring_months", "months":12 (recurring only),
 *    "slabs":[{"from":"0.00","to":"50000.00","percent":"8"}, {"from":"50000.00","to":null,"amount":"6000.00"}]}
 *       — the slab whose [from, to) contains the payment amount; each slab pays a percent or a flat amount
 *
 * Nothing here invents a rate: a plan only exists once an Engage admin writes and publishes one.
 */
final class CommissionRules
{
    public const TYPES = ['percent_of_first_payment', 'percent_recurring_months', 'flat_per_conversion', 'slab'];

    /**
     * Validate and normalise. Returns [normalisedRules, errors].
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public static function normalise(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [[], ['rules' => 'rules must be an object.']];
        }
        $type = (string) ($rules['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            return [[], ['rules.type' => 'rules.type must be one of ' . implode(', ', self::TYPES) . '.']];
        }
        $errors = [];
        $out = ['type' => $type];
        try {
            switch ($type) {
                case 'percent_of_first_payment':
                    $out['percent'] = Money::formatPercentE4(self::positivePercent($rules['percent'] ?? null));
                    break;
                case 'percent_recurring_months':
                    $out['percent'] = Money::formatPercentE4(self::positivePercent($rules['percent'] ?? null));
                    $out['months'] = self::months($rules['months'] ?? null);
                    break;
                case 'flat_per_conversion':
                    $p = Money::toPaise($rules['amount'] ?? null);
                    if ($p <= 0) {
                        throw new InvalidArgumentException('amount must be greater than 0.');
                    }
                    $out['amount'] = Money::format($p);
                    break;
                case 'slab':
                    $appliesTo = (string) ($rules['applies_to'] ?? 'first_payment');
                    if (! in_array($appliesTo, ['first_payment', 'recurring_months'], true)) {
                        throw new InvalidArgumentException('applies_to must be first_payment or recurring_months.');
                    }
                    $out['applies_to'] = $appliesTo;
                    if ($appliesTo === 'recurring_months') {
                        $out['months'] = self::months($rules['months'] ?? null);
                    }
                    $out['slabs'] = self::slabs($rules['slabs'] ?? null);
                    break;
            }
        } catch (InvalidArgumentException $e) {
            $errors['rules'] = $e->getMessage();
        }

        return [$errors === [] ? $out : [], $errors];
    }

    /**
     * Commission for one confirmed payment, or null when the rule does not pay on it.
     *
     * @param array<string,mixed> $rules normalised rules
     * @return array{amount_paise:int, rate_e4:?int, explain:string}|null
     */
    public static function calculate(array $rules, int $paymentSequence, int $basisPaise): ?array
    {
        $type = (string) ($rules['type'] ?? '');
        switch ($type) {
            case 'percent_of_first_payment':
                if ($paymentSequence !== 1) {
                    return null;
                }
                $e4 = Money::percentE4($rules['percent']);

                return ['amount_paise' => Money::applyPercent($basisPaise, $e4), 'rate_e4' => $e4,
                    'explain' => "{$rules['percent']}% of the first payment " . Money::format($basisPaise)];
            case 'percent_recurring_months':
                if ($paymentSequence < 1 || $paymentSequence > (int) $rules['months']) {
                    return null;
                }
                $e4 = Money::percentE4($rules['percent']);

                return ['amount_paise' => Money::applyPercent($basisPaise, $e4), 'rate_e4' => $e4,
                    'explain' => "{$rules['percent']}% of payment #{$paymentSequence} (of {$rules['months']}) " . Money::format($basisPaise)];
            case 'flat_per_conversion':
                if ($paymentSequence !== 1) {
                    return null;
                }

                return ['amount_paise' => Money::toPaise($rules['amount']), 'rate_e4' => null,
                    'explain' => "Flat {$rules['amount']} per conversion"];
            case 'slab':
                $limit = ($rules['applies_to'] ?? 'first_payment') === 'first_payment' ? 1 : (int) ($rules['months'] ?? 0);
                if ($paymentSequence < 1 || $paymentSequence > $limit) {
                    return null;
                }
                foreach ($rules['slabs'] as $slab) {
                    $from = Money::toPaise($slab['from']);
                    $to   = $slab['to'] === null ? null : Money::toPaise($slab['to']);
                    if ($basisPaise < $from || ($to !== null && $basisPaise >= $to)) {
                        continue;
                    }
                    $range = $slab['from'] . '–' . ($slab['to'] ?? '∞');
                    if (isset($slab['percent'])) {
                        $e4 = Money::percentE4($slab['percent']);

                        return ['amount_paise' => Money::applyPercent($basisPaise, $e4), 'rate_e4' => $e4,
                            'explain' => "Slab {$range}: {$slab['percent']}% of payment #{$paymentSequence} " . Money::format($basisPaise)];
                    }

                    return ['amount_paise' => Money::toPaise($slab['amount']), 'rate_e4' => null,
                        'explain' => "Slab {$range}: flat {$slab['amount']}"];
                }

                return null;
        }

        return null;
    }

    private static function positivePercent(mixed $v): int
    {
        $e4 = Money::percentE4($v);
        if ($e4 <= 0) {
            throw new InvalidArgumentException('percent must be greater than 0.');
        }

        return $e4;
    }

    private static function months(mixed $v): int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }
        if (! is_int($v) || $v < 1 || $v > 120) {
            throw new InvalidArgumentException('months must be a whole number from 1 to 120.');
        }

        return $v;
    }

    /** @return list<array<string,mixed>> */
    private static function slabs(mixed $slabs): array
    {
        if (! is_array($slabs) || $slabs === [] || count($slabs) > 20) {
            throw new InvalidArgumentException('slabs must be a list of 1 to 20 slabs.');
        }
        $out = [];
        foreach (array_values($slabs) as $i => $s) {
            if (! is_array($s)) {
                throw new InvalidArgumentException("slab #{$i} must be an object.");
            }
            $from = Money::toPaise($s['from'] ?? '0');
            $to = ($s['to'] ?? null) === null || ($s['to'] ?? '') === '' ? null : Money::toPaise($s['to']);
            if ($to !== null && $to <= $from) {
                throw new InvalidArgumentException("slab #{$i}: to must be greater than from.");
            }
            $hasPct = isset($s['percent']) && $s['percent'] !== '';
            $hasAmt = isset($s['amount']) && $s['amount'] !== '';
            if ($hasPct === $hasAmt) {
                throw new InvalidArgumentException("slab #{$i}: give either percent or amount.");
            }
            $row = ['from' => Money::format($from), 'to' => $to === null ? null : Money::format($to)];
            if ($hasPct) {
                $row['percent'] = Money::formatPercentE4(self::positivePercent($s['percent']));
            } else {
                $row['amount'] = Money::format(Money::toPaise($s['amount']));
            }
            $out[] = $row;
        }
        usort($out, static fn (array $a, array $b): int => Money::toPaise($a['from']) <=> Money::toPaise($b['from']));
        for ($i = 1, $n = count($out); $i < $n; $i++) {
            $prevTo = $out[$i - 1]['to'];
            if ($prevTo === null || Money::toPaise($prevTo) > Money::toPaise($out[$i]['from'])) {
                throw new InvalidArgumentException('slabs must not overlap.');
            }
        }

        return $out;
    }

    /**
     * Product scope: ["*"] or a list of product codes.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    public static function normaliseScope(mixed $scope): array
    {
        if ($scope === null || $scope === '' || $scope === []) {
            return [['*'], null];
        }
        if (is_string($scope)) {
            $scope = array_map('trim', explode(',', $scope));
        }
        if (! is_array($scope)) {
            return [[], 'product_scope must be a list of product codes or ["*"].'];
        }
        $codes = [];
        foreach ($scope as $c) {
            if (! is_string($c) || ! preg_match('/\A(\*|[a-z0-9_.\-]{1,64})\z/i', trim($c))) {
                return [[], 'product_scope contains an invalid product code.'];
            }
            $codes[] = trim($c);
        }
        $codes = array_values(array_unique($codes));
        if (in_array('*', $codes, true)) {
            return [['*'], null];
        }

        return [$codes, null];
    }

    /** @param list<string> $a @param list<string> $b */
    public static function scopesOverlap(array $a, array $b): bool
    {
        return in_array('*', $a, true) || in_array('*', $b, true) || array_intersect($a, $b) !== [];
    }

    /** @param list<string> $scope */
    public static function scopeCovers(array $scope, ?string $productCode): bool
    {
        if (in_array('*', $scope, true)) {
            return true;
        }

        return $productCode !== null && $productCode !== '' && in_array($productCode, $scope, true);
    }
}

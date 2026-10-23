<?php
declare(strict_types=1);

namespace App\Services\Coupons;

/**
 * Coupon pricing in integer paise (docs/COUPONS.md §4, contract §4.1, founder decision D-C1: GST on the price after
 * discount). No floats anywhere.
 *
 * Inputs: the offer's list price L (paise, on the offer's own price basis), its GST rate R (percent, two decimals,
 * held as basis points of a percent: 18 % → 1800) and price basis (inclusive | exclusive of GST), and the coupon.
 *
 *   discount D   percent: floor(L × percent_bp / 10000)          flat: flat_paise
 *                then  D = min(D, max_discount_paise)            (when the coupon has a cap)
 *                then  D = min(D, L − 100)                       (net never below ₹1 — a free plan is a complimentary
 *                                                                 licence, not a coupon)
 *                D < 1 → the coupon does not apply (not_applicable).
 *   net N        N = L − D, on the SAME basis as the offer price (₹X off an inclusive price is ₹X off what the
 *                customer pays; ₹X off an exclusive price is ₹X off the taxable value).
 *   GST on N     exactly Finance's GstCalculator::line (round = half up, to the paisa):
 *                  exclusive: taxable = N, gst = round(N × R / 100),         total = N + gst
 *                  inclusive: gst = round(N × R / (100 + R)), taxable = N − gst, total = N
 *
 * Finance invoices the line with amount = N (list_amount = L, discount = D); its unchanged GstCalculator therefore
 * produces the same taxable value and GST as this class. Seller commission basis = taxable (net before GST).
 */
final class PricingService
{
    public const MIN_NET_PAISE = 100;

    /** "18", "18.00", "12.5" → 1800 / 1800 / 1250; null when not a rate 0 ≤ R < 100 with at most two decimals. */
    public static function rateBp(mixed $rate): ?int
    {
        $s = is_int($rate) ? (string) $rate : (is_string($rate) ? trim($rate) : '');
        if (! preg_match('/\A(\d{1,2})(?:\.(\d{1,2}))?\z/', $s, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** round_half_up(num / den) for num ≥ 0, den > 0 (as Finance). */
    public static function roundDiv(int $num, int $den): int
    {
        return intdiv($num * 2 + $den, $den * 2);
    }

    /**
     * Discount for a list price under a coupon rule.
     *
     * @param array{discount_type:string, percent_bp?:int|string|null, flat_paise?:int|string|null, max_discount_paise?:int|string|null} $coupon
     */
    public static function discount(int $listPaise, array $coupon): int
    {
        if ($listPaise <= self::MIN_NET_PAISE) {
            return 0;
        }
        if ($coupon['discount_type'] === 'percent') {
            $d = intdiv($listPaise * (int) $coupon['percent_bp'], 10000);
        } elseif ($coupon['discount_type'] === 'flat') {
            $d = (int) $coupon['flat_paise'];
        } else {
            throw new \InvalidArgumentException('discount_type must be percent or flat.');
        }
        if (($coupon['max_discount_paise'] ?? null) !== null && $coupon['max_discount_paise'] !== '') {
            $d = min($d, (int) $coupon['max_discount_paise']);
        }

        return max(0, min($d, $listPaise - self::MIN_NET_PAISE));
    }

    /**
     * GST on an amount as billed (Finance GstCalculator::line, without the CGST/SGST split).
     *
     * @return array{taxable:int, gst:int, total:int}
     */
    public static function gst(int $amountPaise, int $rateBp, string $basis): array
    {
        if ($amountPaise < 0 || $rateBp < 0 || $rateBp >= 10000) {
            throw new \InvalidArgumentException('Amount must be ≥ 0 and the rate below 100 %.');
        }
        if ($basis === 'inclusive') {
            $gst = self::roundDiv($amountPaise * $rateBp, 10000 + $rateBp);

            return ['taxable' => $amountPaise - $gst, 'gst' => $gst, 'total' => $amountPaise];
        }
        if ($basis === 'exclusive') {
            $gst = self::roundDiv($amountPaise * $rateBp, 10000);

            return ['taxable' => $amountPaise, 'gst' => $gst, 'total' => $amountPaise + $gst];
        }
        throw new \InvalidArgumentException('price_basis must be inclusive or exclusive.');
    }

    /**
     * The full breakdown. $coupon null = no discount.
     *
     * @return array{list_paise:int, discount_paise:int, net_paise:int, taxable_paise:int, gst_paise:int, total_paise:int,
     *               gst_rate:string, rate_bp:int, price_basis:string, list_taxable_paise:int, list_gst_paise:int, list_total_paise:int}
     */
    public static function quote(int $listPaise, string $gstRate, string $basis, ?array $coupon = null): array
    {
        $rateBp = self::rateBp(self::trimRate($gstRate));
        if ($rateBp === null) {
            throw new \InvalidArgumentException('Invalid GST rate ' . $gstRate);
        }
        $d = $coupon !== null ? self::discount($listPaise, $coupon) : 0;
        $net = $listPaise - $d;
        $g = self::gst($net, $rateBp, $basis);
        $l = self::gst($listPaise, $rateBp, $basis);

        return [
            'list_paise'         => $listPaise,
            'discount_paise'     => $d,
            'net_paise'          => $net,
            'taxable_paise'      => $g['taxable'],
            'gst_paise'          => $g['gst'],
            'total_paise'        => $g['total'],
            'gst_rate'           => self::trimRate($gstRate),
            'rate_bp'            => $rateBp,
            'price_basis'        => $basis,
            'list_taxable_paise' => $l['taxable'],
            'list_gst_paise'     => $l['gst'],
            'list_total_paise'   => $l['total'],
        ];
    }

    /** "18.00" → "18", "12.50" → "12.5". */
    public static function trimRate(string $r): string
    {
        $r = trim($r);

        return str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r;
    }
}

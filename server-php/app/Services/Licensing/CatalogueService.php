<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Coupons\PricingService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The catalogue as buyers see it, per sales channel (docs/SELLING_LICENCES.md §5–§6): offers on sale now on that
 * channel, grouped by product, each with its price worked out the way Finance invoices it (PricingService — GST on the
 * price as billed, integer paise, round half up) and the price revision scheduled for it, if any, with its effective
 * date. Type-agnostic: every label is data typed by staff.
 *
 * Used by GET v1/website/catalogue (aicountly.com) and the seller portal's licence catalogue.
 */
class CatalogueService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /**
     * @param bool $pricedOnly leave out free offers and the products' Basic (what a seller can buy)
     * @return list<array{code:string, label:string, category:?string, offers:list<array<string,mixed>>}>
     */
    public function forChannel(string $channel, bool $pricedOnly = false): array
    {
        $offers = new OfferService($this->db());
        $pending = $offers->pendingRevisions();
        $products = [];
        foreach ($offers->onSale($channel) as $o) {
            if (LicenceService::coversCourse($o)) {
                continue;
            }
            $isDefault = EntitlementProjector::bool($o['is_default'] ?? false);
            if ($pricedOnly && ($isDefault || (int) $o['price_paise'] <= 0)) {
                continue;
            }
            $code = (string) $o['product_code'];
            $products[$code] ??= ['code' => $code, 'label' => (string) ($o['product_label'] ?? $code), 'offers' => []];
            $products[$code]['offers'][] = self::offerView($o, $pending[(string) $o['offer_code']] ?? null);
        }
        foreach ($products as &$p) {
            // Basic first, then by price, so a product page reads cheapest to dearest.
            usort($p['offers'], static fn (array $a, array $b): int => [$b['is_default'], $a['price']['total_paise'], $a['offer_code']]
                <=> [$a['is_default'], $b['price']['total_paise'], $b['offer_code']]);
        }
        unset($p);
        ksort($products);

        return array_values($products);
    }

    /** One offer as a buyer sees it. @param array<string,mixed> $o an OfferService row @param array<string,mixed>|null $upcoming */
    public static function offerView(array $o, ?array $upcoming = null): array
    {
        $v = OfferService::view($o);
        $display = (array) $v['display'];
        $attributes = [];
        foreach ((array) ($display['attributes'] ?? []) as $a) {
            if (is_array($a) && isset($a['label'])) {
                $attributes[] = ['label' => (string) $a['label'], 'value' => $a['value'] ?? null];
            }
        }
        $cycle = is_string($display['cycle_label'] ?? null) && $display['cycle_label'] !== ''
            ? $display['cycle_label'] : S::cycleLabel($v['term_months'], $v['term_days']);

        return [
            'offer_code'   => $v['offer_code'],
            'version'      => $v['version'],
            'label'        => $v['label'],
            'description'  => $v['description'],
            'product'      => $v['product'],
            'licence_type' => $v['licence_type'],
            'plan'         => $v['plan'],
            'is_default'   => $v['is_default'],
            'price'        => self::price($o),
            'term'         => ['months' => $v['term_months'], 'days' => $v['term_days'], 'cycle' => $cycle],
            'seats'        => $v['seats'],
            // Additive (docs/LICENSING.md §3.6): workspace = every company the customer owns; company = one company at a
            // time, which the customer chooses in Manage and may move.
            'licence_scope' => $v['licence_scope'],
            'entitlements' => $v['entitlements'],
            'attributes'   => $attributes,
            'display'      => $v['display'],
            'effective_from' => $v['effective_from'],
            'valid_to'     => $v['valid_to'],
            'upcoming'     => $upcoming !== null ? [
                'version'        => (int) $upcoming['version'],
                'effective_from' => S::iso((string) $upcoming['valid_from']),
                'price'          => self::price($upcoming),
            ] : null,
        ];
    }

    /**
     * The price of an offer as billed: list price on its basis and the GST Finance will charge on it.
     *
     * @param array<string,mixed> $o
     * @return array{currency:string, amount_paise:int, basis:string, gst_rate:string, sac:?string, taxable_paise:int, gst_paise:int, total_paise:int}
     */
    public static function price(array $o): array
    {
        $list = (int) $o['price_paise'];
        $basis = (string) $o['price_basis'];
        $rate = OfferService::rate((string) $o['gst_rate']);
        try {
            $q = PricingService::quote($list, $rate, $basis);
            $taxable = $q['taxable_paise'];
            $gst = $q['gst_paise'];
            $total = $q['total_paise'];
        } catch (\InvalidArgumentException) {
            // A rate PricingService cannot price (100 %): say only what the offer says.
            [$taxable, $gst, $total] = [$basis === 'exclusive' ? $list : 0, 0, $list];
        }

        return [
            'currency' => (string) ($o['currency'] ?? 'INR'), 'amount_paise' => $list, 'basis' => $basis, 'gst_rate' => $rate,
            'sac' => $o['sac'] ?? null, 'taxable_paise' => $taxable, 'gst_paise' => $gst, 'total_paise' => $total,
        ];
    }
}

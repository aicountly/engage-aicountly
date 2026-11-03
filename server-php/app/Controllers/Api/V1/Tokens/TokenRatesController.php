<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Tokens;

use App\Controllers\BaseApiController;
use App\Services\Tokens\ConsoleModelCatalogue;
use App\Services\Tokens\TokenRateService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff: token conversion rates (docs/TOKEN_CONVERSIONS.md).
 *
 * View: super_admin | sales_manager | seller_manager. Create / edit / activate / retire and the
 * pricing settings: super_admin | sales_manager — the same split the coupon engine uses, because
 * this decides what a customer is charged just as directly.
 *
 *   GET  v1/token-rates ?status=&provider_code=
 *   POST v1/token-rates                      → draft
 *   PUT  v1/token-rates/{id}                 draft only (an active rate is immutable)
 *   POST v1/token-rates/{id}/activate        four eyes
 *   POST v1/token-rates/{id}/retire  {reason}
 *   GET  v1/token-rates/catalogue            every Console model priced against the live card,
 *                                            with the ones nobody has rated named as warnings
 *   GET  v1/token-rates/pricing              what 1,000 tokens cost, GST, SAC, tier fallbacks
 *   PUT  v1/token-rates/pricing              change them
 */
class TokenRatesController extends BaseApiController
{
    private function result(array $r): ResponseInterface
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'] ?? [], $status);
        }
        $body = ['ok' => false, 'error' => (string) ($r['error'] ?? 'Request failed.')];
        foreach (['code', 'details'] as $k) {
            if (isset($r[$k])) {
                $body[$k] = $r[$k];
            }
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    public function index()
    {
        $q = $this->request->getGet();

        return $this->ok(['items' => (new TokenRateService())->list(is_array($q) ? $q : [])]);
    }

    public function create()
    {
        return $this->result((new TokenRateService())->create($this->input(), $this->user()));
    }

    public function update($id = null)
    {
        return $this->result((new TokenRateService())->update((int) $id, $this->input(), $this->user()));
    }

    public function activate($id = null)
    {
        return $this->result((new TokenRateService())->activate((int) $id, $this->user()));
    }

    public function retire($id = null)
    {
        return $this->result((new TokenRateService())->retire((int) $id, $this->input(), $this->user()));
    }

    /**
     * Every model Console knows, priced against the live card.
     *
     * A model no Console module is bound to is returned with in_use false rather than left out:
     * staff asked to see the whole catalogue with the unused models marked inactive, so that
     * setting a rate ahead of binding a model is possible and so that nothing is a surprise later.
     *
     * When Console cannot be reached the rates are still returned, with `catalogue_available`
     * false and the reason — a rate card is readable and editable even when the catalogue behind
     * it is not, and saying "no models" would be a lie that invites someone to re-enter them.
     */
    public function catalogue()
    {
        $svc = new TokenRateService();
        $cat = (new ConsoleModelCatalogue())->models();
        $priced = $svc->priceCatalogue($cat['models']);

        return $this->ok([
            'catalogue_available' => $cat['ok'],
            'catalogue_error'     => $cat['error'],
            'models'              => $priced['models'],
            'warnings'            => $priced['warnings'],
            'rates'               => $svc->list(['status' => 'active']),
            'pricing'             => $svc->pricing(),
            'tier_fallback'       => $svc->tierFallback(),
            'unclassified_tier'   => $svc->unclassifiedTier(),
        ]);
    }

    public function pricing()
    {
        $svc = new TokenRateService();

        return $this->ok([
            'pricing'           => $svc->pricing(),
            'tier_fallback'     => $svc->tierFallback(),
            'unclassified_tier' => $svc->unclassifiedTier(),
            // Worked through, so nobody has to do the arithmetic to check the setting is right.
            'example'           => [
                'tokens'        => 1000000,
                'taxable_paise' => $svc->paiseForTokens(1000000),
                'note'          => 'What 1,000,000 tokens cost before GST.',
            ],
        ]);
    }

    public function setPricing()
    {
        return $this->result((new TokenRateService())->savePricing($this->input(), $this->user()));
    }
}

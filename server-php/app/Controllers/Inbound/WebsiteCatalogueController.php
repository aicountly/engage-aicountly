<?php
declare(strict_types=1);

namespace App\Controllers\Inbound;

use App\Libraries\AicS2s\AicS2sResponse;
use App\Services\Licensing\CatalogueService;
use CodeIgniter\Controller;

/**
 * www.aicountly.com (its PHP server) → Engage: the plans to show against each product (docs/SELLING_LICENCES.md §6).
 * Filter `aic-s2s:website,read` — AIC-S2S v1, client `website`, a read key (503 inbound_not_configured until
 * ENGAGE_INBOUND_WEBSITE_* is set). The browser never calls Engage: the site fetches this, caches it and renders it.
 *
 *   GET v1/website/catalogue
 *   → {success, data: {generated_at, currency, products: [{code, label, offers: [CatalogueService::offerView]}]}, request_id}
 *
 * Only offers on sale now on the `website` channel (a Basic is listed too when it is on that channel), each with its
 * GST worked out as Finance bills it and the price revision scheduled for it ("upcoming", with its effective date).
 * Public information, so reads are not written to the audit log.
 */
class WebsiteCatalogueController extends Controller
{
    public function catalogue()
    {
        $rid = (string) ($this->request->aicS2s['request_id'] ?? AicS2sResponse::requestId(''));
        try {
            $products = (new CatalogueService())->forChannel('website');
        } catch (\Throwable $e) {
            log_message('error', 'Website catalogue failed: ' . $e->getMessage());

            return AicS2sResponse::error($this->response, 503, 'database_unavailable', 'The catalogue is temporarily unavailable.', $rid, true);
        }

        return AicS2sResponse::success($this->response, [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'currency'     => 'INR',
            'products'     => $products,
        ], null, $rid)->setHeader('Cache-Control', 'private, max-age=60');
    }
}

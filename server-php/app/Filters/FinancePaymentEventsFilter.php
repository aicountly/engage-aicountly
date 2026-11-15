<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Auth for finance.aicountly.org → Engage payment events (POST /api/v1/inbound/finance/payment-events).
 *
 * Finance signs these with AIC-S2S v1 as client `finance` (its EngagePaymentEventOutbox: X-AIC-Client: finance, an
 * issue-scope key — FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID / _SECRET there, ENGAGE_INBOUND_FINANCE_KEY_IDS /
 * _SECRET_<KEYID> / _SCOPE_<KEYID>=issue here). The route used to accept only X-Finance-Key, so Finance's signed events
 * were refused with 401 and no payment could ever settle an order. A signed request is now checked as AIC-S2S
 * (AicS2sFilter, client finance, scope issue); one carrying X-Finance-Key and no signature is still checked against
 * ENGAGE_INBOUND_FINANCE_KEY (FinanceInboundFilter), so nothing that worked before stops working.
 */
class FinancePaymentEventsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (trim($request->getHeaderLine('X-AIC-Signature')) !== '' || trim($request->getHeaderLine('X-AIC-Client')) !== '') {
            return (new AicS2sFilter())->before($request, ['finance', 'issue']);
        }

        return (new FinanceInboundFilter())->before($request, $arguments);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

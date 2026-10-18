<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Server-to-server auth for finance.aicountly.org → Engage (POST /api/v1/inbound/finance/payout-status).
 *
 *   X-Finance-Key: <ENGAGE_INBOUND_FINANCE_KEY>   (≥ 32 characters; Finance holds the same value)
 *
 * 503 finance_key_missing when Engage's own key is unset / shorter than 32 characters (fail closed);
 * 401 finance_key_invalid when the header is absent or wrong; constant-time compare.
 */
class FinanceInboundFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = trim((string) env('ENGAGE_INBOUND_FINANCE_KEY', ''));
        if (strlen($expected) < 32) {
            return service('response')->setStatusCode(503)->setJSON([
                'ok' => false, 'code' => 'finance_key_missing',
                'error' => 'Server misconfigured: ENGAGE_INBOUND_FINANCE_KEY missing or shorter than 32 characters in api/.env',
            ]);
        }
        $provided = (string) $request->getHeaderLine('X-Finance-Key');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')->setStatusCode(401)->setJSON(['ok' => false, 'code' => 'finance_key_invalid', 'error' => 'Invalid X-Finance-Key.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Server-to-server auth for /api/v1/seller-portal/* (seller.aicountly.com BFF → Engage).
 *
 *   X-Seller-Portal-Key: <SELLER_PORTAL_KEY>   (same value in both .env files, ≥ 32 characters)
 *
 * 503 portal_key_missing when Engage's own key is unset / too short; 401 portal_key_invalid when the header is
 * absent or wrong; constant-time compare.
 * Because only the BFF holds the key, its forwarded visitor IP / User-Agent (X-Seller-Client-IP /
 * X-Seller-Client-UA) are trusted for rate limits and audit; they are ignored on every other route.
 */
class SellerPortalKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = trim((string) env('SELLER_PORTAL_KEY', ''));
        if (strlen($expected) < 32) {
            return service('response')->setStatusCode(503)->setJSON([
                'ok' => false, 'code' => 'portal_key_missing',
                'error' => 'Server misconfigured: SELLER_PORTAL_KEY missing or shorter than 32 characters in api/.env',
            ]);
        }
        $provided = (string) $request->getHeaderLine('X-Seller-Portal-Key');
        if ($provided === '') {
            return service('response')->setStatusCode(401)->setJSON(['ok' => false, 'code' => 'portal_key_invalid', 'error' => 'Missing X-Seller-Portal-Key.']);
        }
        if (! hash_equals($expected, $provided)) {
            return service('response')->setStatusCode(401)->setJSON(['ok' => false, 'code' => 'portal_key_invalid', 'error' => 'Invalid X-Seller-Portal-Key.']);
        }

        $ip = trim((string) $request->getHeaderLine('X-Seller-Client-IP'));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $xff = trim(explode(',', (string) $request->getHeaderLine('X-Forwarded-For'))[0] ?? '');
            $ip = filter_var($xff, FILTER_VALIDATE_IP) !== false ? $xff : $request->getIPAddress();
        }
        $ua = (string) $request->getHeaderLine('X-Seller-Client-UA');
        $request->sellerClient = [
            'ip' => $ip,
            'ua' => substr($ua !== '' ? $ua : (string) $request->getUserAgent(), 0, 255),
            'request_id' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $request->getHeaderLine('X-Request-Id')) ?? '', 0, 64),
        ];
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

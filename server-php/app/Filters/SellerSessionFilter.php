<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Seller session for authenticated seller-portal calls (after seller-portal-key):
 *
 *   X-Seller-Session: <opaque session token from login / activation>
 *
 * 401 session_expired (unknown, revoked, idle > 8 h, older than 7 days); 403 seller_suspended /
 * seller_terminated when access was ended by Engage staff.
 */
class SellerSessionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $token = trim((string) $request->getHeaderLine('X-Seller-Session'));
        if ($token === '') {
            return service('response')->setStatusCode(401)->setJSON(['ok' => false, 'code' => 'session_expired', 'error' => 'Sign in to continue.']);
        }
        try {
            $r = Services::sellerAccounts()->resolveSession($token);
        } catch (\Throwable $e) {
            log_message('error', 'Seller session lookup failed: ' . $e->getMessage());

            return service('response')->setStatusCode(503)->setJSON(['ok' => false, 'code' => 'unavailable', 'error' => 'Could not verify the session. Try again shortly.']);
        }
        if (! $r['ok']) {
            return service('response')->setStatusCode($r['status'])->setJSON(['ok' => false, 'code' => $r['code'], 'error' => $r['error']]);
        }
        $request->seller = $r['seller'];
        $request->sellerSession = $r['session'];
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

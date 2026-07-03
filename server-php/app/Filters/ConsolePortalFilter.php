<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Long-lived shared-secret auth for portal/bot/* endpoints Console calls back
 * into Engage.
 *
 * Console sends:  Authorization: Bearer <ENGAGE_SERVICE_KEY>
 * We compare with constant-time hash_equals; never log the secret.
 */
class ConsolePortalFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = (string) env('ENGAGE_SERVICE_KEY', '');
        if ($expected === '') {
            return service('response')->setStatusCode(503)->setJSON([
                'ok'    => false,
                'error' => 'Server misconfigured: ENGAGE_SERVICE_KEY missing in api/.env',
            ]);
        }

        $header  = (string) $request->getHeaderLine('Authorization');
        $provided = '';
        if (preg_match('/^Bearer\\s+(\\S+)$/i', $header, $m)) {
            $provided = $m[1];
        }

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')->setStatusCode(401)->setJSON([
                'ok'    => false,
                'error' => 'Invalid Console portal token.',
            ]);
        }

        $request->consolePortal = [
            'portal_code' => (string) $request->getHeaderLine('X-Console-Portal') ?: 'console',
        ];
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared-secret auth for internal cross-portal ingest endpoints (Reach -> Engage).
 * Sender must include header:  X-Portal-Token: <REACH_INBOUND_TOKEN>
 */
class InternalTokenFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $expected = (string) env('REACH_INBOUND_TOKEN', '');
        if ($expected === '') {
            return service('response')->setStatusCode(503)->setJSON([
                'ok'    => false,
                'error' => 'Server misconfigured: REACH_INBOUND_TOKEN missing in api/.env',
            ]);
        }

        $provided = (string) $request->getHeaderLine('X-Portal-Token');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return service('response')->setStatusCode(401)->setJSON([
                'ok'    => false,
                'error' => 'Invalid X-Portal-Token.',
            ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

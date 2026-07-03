<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class JwtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\\s+(\\S+)$/i', $header, $m)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Missing or malformed Authorization header.']);
        }

        $payload = null;
        try {
            $payload = Services::jwt()->decode($m[1]);
        } catch (\Throwable $e) {
            return service('response')
                ->setStatusCode(503)
                ->setJSON(['ok' => false, 'error' => 'Server misconfigured: ENGAGE_JWT_SECRET in api/.env']);
        }
        if (! $payload) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Invalid or expired token.']);
        }

        $request->engageUser = [
            'id'    => (int) $payload['sub'],
            'email' => $payload['email'] ?? '',
            'roles' => array_values($payload['roles'] ?? []),
        ];
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

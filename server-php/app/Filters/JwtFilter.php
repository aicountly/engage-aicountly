<?php

namespace App\Filters;

use App\Libraries\ConsoleRoleSync;
use App\Models\UsersModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Bearer JWT validation for Engage staff.
 *
 * Besides the signature/expiry check, the user row is read on every request
 * so that changes made in Console take effect without waiting for the JWT
 * to expire:
 *   - inactive users are rejected;
 *   - tokens issued before engage_users.tokens_revoked_at (set when Console
 *     revokes access at refresh) are rejected;
 *   - $request->engageUser['roles'] is the current engage_user_roles set
 *     (mirrored from Console), not the snapshot inside the token.
 */
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

        $userId = (int) ($payload['sub'] ?? 0);
        try {
            $users = new UsersModel();
            $user  = $userId > 0 ? $users->find($userId) : null;
            $roles = $user ? $users->roleCodes($userId) : [];
        } catch (\Throwable $e) {
            log_message('error', 'JwtFilter user lookup failed: ' . $e->getMessage());

            return service('response')
                ->setStatusCode(503)
                ->setJSON(['ok' => false, 'error' => 'Could not verify the session. Try again shortly.']);
        }

        if (! $user || ($user['status'] ?? 'active') !== 'active') {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Session is no longer active. Sign in again from Console.']);
        }

        $revokedAt = (string) ($user['tokens_revoked_at'] ?? '');
        if ($revokedAt !== '' && (int) ($payload['iat'] ?? 0) <= (int) strtotime($revokedAt)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['ok' => false, 'error' => 'Session is no longer active. Sign in again from Console.']);
        }

        if ($roles === []) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['ok' => false, 'error' => ConsoleRoleSync::DENY_MESSAGE]);
        }

        $request->engageUser = [
            'id'    => $userId,
            'email' => (string) ($user['email'] ?? ($payload['email'] ?? '')),
            'roles' => array_values($roles),
        ];
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

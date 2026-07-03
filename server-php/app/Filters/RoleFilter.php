<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Usage in Routes.php:
 *   $routes->put('settings', '...', ['filter' => 'role:super_admin']);
 *
 * The filter expects request->engageUser populated by JwtFilter.
 * Engage is a superadmin-only portal, so the default role is `super_admin`.
 */
class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = $request->engageUser ?? null;
        if (! $user) {
            return service('response')->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'Not authenticated.']);
        }

        $allowed = array_values(array_filter($arguments ?? []));
        if ($allowed === []) {
            return;
        }

        $userRoles = array_values($user['roles'] ?? []);
        $ok = (bool) array_intersect($allowed, $userRoles);

        if (! $ok) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'ok'       => false,
                    'error'    => 'Forbidden — insufficient role.',
                    'required' => $allowed,
                    'present'  => $userRoles,
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

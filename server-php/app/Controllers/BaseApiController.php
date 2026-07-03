<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

abstract class BaseApiController extends Controller
{
    protected function user(): ?array
    {
        return $this->request->engageUser ?? null;
    }

    protected function userHasRole(array|string $roles): bool
    {
        $allowed = is_array($roles) ? $roles : [$roles];
        $present = (array) ($this->user()['roles'] ?? []);
        return (bool) array_intersect($allowed, $present);
    }

    protected function ok(mixed $data = [], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'ok'   => true,
            'data' => $data,
        ]);
    }

    protected function fail(string $message, int $status = 400, mixed $extra = null): ResponseInterface
    {
        $body = ['ok' => false, 'error' => $message];
        if ($extra !== null) {
            $body['details'] = $extra;
        }
        return $this->response->setStatusCode($status)->setJSON($body);
    }

    protected function audit(string $event, array $opts = []): void
    {
        Services::auditService()->log($event, $opts);
    }

    /**
     * Read JSON body or fall back to form data.
     */
    protected function input(): array
    {
        $json = $this->request->getJSON(true);
        if (is_array($json)) {
            return $json;
        }
        return (array) $this->request->getPost();
    }

    protected function paging(): array
    {
        $q = $this->request->getGet();
        $page  = max(1, (int) ($q['page']  ?? 1));
        $limit = min(500, max(1, (int) ($q['limit'] ?? 50)));
        return [$page, $limit, ($page - 1) * $limit];
    }
}

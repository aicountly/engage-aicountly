<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Idempotency-Key replay for seller-portal writes (contract: every call idempotent).
 *
 * The first request with a key stores its answer (engage_idempotency_keys, kept 24 h); a retry with the
 * same key and the same body gets that answer again (header Idempotent-Replayed: true) without repeating
 * the write. Same key, different body → 409 conflict. Still running → 409 conflict (retry later).
 * 5xx answers are not stored, so the request can be retried. No key → no replay (natural keys apply).
 * Scope: the signed-in seller (or the visitor IP for anonymous calls) + method + path.
 * Not applied to login / activation / reset: their answers carry session tokens, which are never stored.
 */
class IdempotencyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $key = trim((string) $request->getHeaderLine('Idempotency-Key'));
        if ($key === '' || strtoupper($request->getMethod()) === 'GET') {
            return;
        }
        if (! preg_match('/\A[A-Za-z0-9_.:\-]{8,128}\z/', $key)) {
            return service('response')->setStatusCode(422)->setJSON(['ok' => false, 'code' => 'validation_failed', 'error' => 'Idempotency-Key must be 8–128 characters of A-Z a-z 0-9 _ . : -']);
        }
        $who = isset($request->seller['id']) ? 'seller:' . $request->seller['id'] : 'ip:' . ($request->sellerClient['ip'] ?? $request->getIPAddress());
        $scope = substr($who . ':' . strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath(), 0, 160);
        $files = [];
        foreach ($_FILES as $f) {
            if (is_array($f) && is_string($f['tmp_name'] ?? null) && is_file($f['tmp_name'])) {
                $files[] = hash_file('sha256', $f['tmp_name']);
            }
        }
        $hash = hash('sha256', (string) $request->getBody() . '|' . json_encode($request->getPost()) . '|' . implode(',', $files));

        $db = Database::connect();
        $row = $db->query(
            'INSERT INTO engage_idempotency_keys (scope, idem_key, request_hash) VALUES (?, ?, ?) ON CONFLICT (scope, idem_key) DO NOTHING RETURNING id',
            [$scope, $key, $hash]
        )->getRowArray();
        if ($row) {
            $request->idempotencyRowId = (int) $row['id'];

            return;
        }
        $prev = $db->table('engage_idempotency_keys')->where('scope', $scope)->where('idem_key', $key)->get()->getRowArray();
        if (! $prev || $prev['request_hash'] !== $hash) {
            return service('response')->setStatusCode(409)->setJSON(['ok' => false, 'code' => 'conflict', 'error' => 'This Idempotency-Key was already used for a different request.']);
        }
        if ($prev['response_status'] === null) {
            return service('response')->setStatusCode(409)->setJSON(['ok' => false, 'code' => 'conflict', 'error' => 'The same request is still being processed. Retry shortly.']);
        }

        return service('response')->setStatusCode((int) $prev['response_status'])
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setHeader('Idempotent-Replayed', 'true')
            ->setBody((string) $prev['response_body']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $id = $request->idempotencyRowId ?? null;
        if ($id === null) {
            return;
        }
        $db = Database::connect();
        $status = $response->getStatusCode();
        if ($status >= 500) {
            $db->table('engage_idempotency_keys')->where('id', $id)->delete();

            return;
        }
        $db->table('engage_idempotency_keys')->where('id', $id)->update(['response_status' => $status, 'response_body' => (string) $response->getBody()]);
    }
}

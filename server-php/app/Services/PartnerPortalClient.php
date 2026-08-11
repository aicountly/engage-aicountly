<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Outbound client for the Partner Portal's admin API (partner.aicountly.com).
 *
 * Partner data lives ONLY on the Partner Portal — Engage stores no partner
 * rows of its own. Every Add/Edit/Delete/List action from Engage's Partner
 * Master screen is relayed there through this client. Calls carry a shared
 * secret:
 *
 *   X-Partner-Admin-Key: PARTNER_PORTAL_ADMIN_KEY
 *
 * which must match PARTNER_ADMIN_KEY in the Partner Portal's api/.env.
 */
class PartnerPortalClient
{
    private ?Client $http = null;
    private string $baseUrl;
    private string $key;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('PARTNER_PORTAL_API_URL', ''), '/');
        $this->key     = (string) env('PARTNER_PORTAL_ADMIN_KEY', '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->key !== '';
    }

    private function client(): Client
    {
        if ($this->http instanceof Client) {
            return $this->http;
        }
        $this->http = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => 10,
            'headers'  => [
                'X-Partner-Admin-Key' => $this->key,
                'Content-Type'        => 'application/json',
                'Accept'              => 'application/json',
            ],
        ]);

        return $this->http;
    }

    /**
     * @return array{ok: bool, status: int, data?: mixed, error?: string, details?: mixed}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Partner Portal integration not configured (set PARTNER_PORTAL_API_URL and PARTNER_PORTAL_ADMIN_KEY in api/.env).',
            ];
        }

        try {
            $options = $body !== null ? ['json' => $body] : [];
            $res     = $this->client()->request($method, ltrim($path, '/'), $options);

            return $this->decode((int) $res->getStatusCode(), (string) $res->getBody());
        } catch (GuzzleException $e) {
            // 4xx/5xx from the Partner Portal still carry a useful { ok, error,
            // details } body — relay it verbatim instead of masking it.
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            if ($response !== null) {
                return $this->decode($response->getStatusCode(), (string) $response->getBody());
            }

            log_message('error', 'Partner Portal admin API call failed: ' . $e->getMessage());

            return [
                'ok'     => false,
                'status' => 502,
                'error'  => 'Could not reach the Partner Portal. Please try again.',
            ];
        }
    }

    private function decode(int $status, string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'status' => 502, 'error' => 'Partner Portal returned an unexpected response.'];
        }

        $decoded['status'] = $status;

        return $decoded;
    }

    public function list(array $query = []): array
    {
        $qs = $query === [] ? '' : ('?' . http_build_query($query));

        return $this->request('GET', 'v1/admin/partners' . $qs);
    }

    public function show(int $id): array
    {
        return $this->request('GET', "v1/admin/partners/{$id}");
    }

    public function create(array $data): array
    {
        return $this->request('POST', 'v1/admin/partners', $data);
    }

    public function update(int $id, array $data): array
    {
        return $this->request('PUT', "v1/admin/partners/{$id}", $data);
    }

    public function delete(int $id): array
    {
        return $this->request('DELETE', "v1/admin/partners/{$id}");
    }

    public function activate(int $id): array
    {
        return $this->request('POST', "v1/admin/partners/{$id}/activate");
    }

    public function deactivate(int $id): array
    {
        return $this->request('POST', "v1/admin/partners/{$id}/deactivate");
    }

    public function setPassword(int $id, array $data): array
    {
        return $this->request('POST', "v1/admin/partners/{$id}/password", $data);
    }

    public function unlock(int $id): array
    {
        return $this->request('POST', "v1/admin/partners/{$id}/unlock");
    }

    public function restore(int $id): array
    {
        return $this->request('POST', "v1/admin/partners/{$id}/restore");
    }
}

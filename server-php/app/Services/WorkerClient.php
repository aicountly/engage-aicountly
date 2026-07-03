<?php

namespace App\Services;

use App\Models\WorkerHealthModel;
use GuzzleHttp\Client;

/**
 * Playwright worker client — worker.apis.aicountly.com.
 *
 * Engage only uses the worker for UI review / screenshot / report jobs.
 * All portal-specific bot logic stays inside Engage.
 *
 * Header:  Authorization: Bearer <WORKER_API_TOKEN>
 */
class WorkerClient
{
    private ?Client $http = null;
    private string $baseUrl;
    private string $token;
    private WorkerHealthModel $log;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('WORKER_BASE_URL', ''), '/');
        $this->token   = (string) env('WORKER_API_TOKEN', '');
        $this->log     = new WorkerHealthModel();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    private function client(): Client
    {
        if ($this->http instanceof Client) {
            return $this->http;
        }
        $this->http = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => 60,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
        return $this->http;
    }

    private function call(string $method, string $path, string $kind, array $body = []): ?array
    {
        if (! $this->isConfigured()) {
            $this->log->record([
                'job_kind'      => $kind,
                'status'        => 'skipped',
                'error_message' => 'Worker integration not configured',
                'metadata'      => ['path' => $path],
            ]);
            return null;
        }

        $start = microtime(true);
        try {
            $res  = $this->client()->request($method, ltrim($path, '/'), $body ? ['json' => $body] : []);
            $code = (int) $res->getStatusCode();
            $latency = (int) round((microtime(true) - $start) * 1000);
            $decoded = json_decode((string) $res->getBody(), true);
            $this->log->record([
                'job_kind'    => $kind,
                'status'      => $code >= 200 && $code < 300 ? 'success' : 'error',
                'http_status' => $code,
                'latency_ms'  => $latency,
                'metadata'    => ['path' => $path],
            ]);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            $this->log->record([
                'job_kind'      => $kind,
                'status'        => 'error',
                'http_status'   => 0,
                'latency_ms'    => $latency,
                'error_message' => substr($e->getMessage(), 0, 4000),
                'metadata'      => ['path' => $path],
            ]);
            return null;
        }
    }

    public function screenshot(array $body): ?array
    {
        return $this->call('POST', 'v1/screenshot', 'screenshot', $body);
    }

    public function review(array $body): ?array
    {
        return $this->call('POST', 'v1/review', 'review', $body);
    }

    public function runJob(array $body): ?array
    {
        return $this->call('POST', 'v1/runs', 'run', $body);
    }

    public function ping(): ?array
    {
        return $this->call('GET', 'v1/health', 'health');
    }
}

<?php

namespace App\Controllers\SellerPortal;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Base for /api/v1/seller-portal/* (called only by the seller.aicountly.com BFF).
 * Errors carry a machine `code` next to `error` ({ok:false, error, code, details}) — the BFF maps codes
 * to what the browser sees (seller-aicountly docs/ENGAGE_SELLER_API.md).
 */
abstract class BaseSellerPortalController extends BaseApiController
{
    protected function clientIp(): string
    {
        return (string) ($this->request->sellerClient['ip'] ?? $this->request->getIPAddress());
    }

    protected function clientUa(): string
    {
        return (string) ($this->request->sellerClient['ua'] ?? '');
    }

    /** @return array<string,mixed> */
    protected function seller(): array
    {
        return (array) ($this->request->seller ?? []);
    }

    protected function sessionId(): int
    {
        return (int) ($this->request->sellerSession['id'] ?? 0);
    }

    protected function failCode(int $status, string $code, string $message, ?array $details = null): ResponseInterface
    {
        $body = ['ok' => false, 'error' => $message, 'code' => $code];
        if ($details !== null) {
            $body['details'] = $details;
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    /** Relay a service result ['status', 'data'] | ['status', 'code', 'error', 'details']. */
    protected function result(array $r): ResponseInterface
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'] ?? [], $status);
        }

        return $this->failCode($status, (string) ($r['code'] ?? self::codeFor($status)), (string) ($r['error'] ?? 'Request failed.'), $r['details'] ?? null);
    }

    protected static function codeFor(int $status): string
    {
        return match ($status) {
            401 => 'session_expired', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict',
            410 => 'token_invalid', 422 => 'validation_failed', 429 => 'rate_limited', default => 'unavailable',
        };
    }

    /** Throttle by key; null when allowed, else a 429 response with Retry-After. */
    protected function throttle(string $key, int $capacity, int $seconds): ?ResponseInterface
    {
        $t = Services::throttler();
        if ($t->check(md5('seller-portal:' . $key), $capacity, $seconds)) {
            return null;
        }

        return $this->failCode(429, 'rate_limited', 'Too many requests. Please wait a moment and try again.')
            ->setHeader('Retry-After', (string) max(1, $t->getTokenTime()));
    }

    protected function requireSettled(): ?ResponseInterface
    {
        $s = $this->seller();
        if (($s['status'] ?? '') === 'onboarding') {
            return $this->failCode(403, 'agreement_required', 'Accept the seller agreement first.');
        }

        return null;
    }
}

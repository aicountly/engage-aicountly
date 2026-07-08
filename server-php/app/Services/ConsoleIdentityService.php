<?php

namespace App\Services;

class ConsoleIdentityService
{
    private const COOKIE_NAME = 'aicountly_controller_token';

    public static function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    public function exchangeLaunchToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $appCode = strtolower(trim((string) env('CONTROLLER_APP_CODE', 'engage')));

        return $this->requestJson('/auth/sso/exchange', [
            'token'    => $token,
            'app_code' => $appCode,
        ]);
    }

    public function introspectSession(string $consoleToken): ?array
    {
        $consoleToken = trim($consoleToken);
        if ($consoleToken === '') {
            return null;
        }

        $appCode = strtolower(trim((string) env('CONTROLLER_APP_CODE', 'engage')));

        return $this->requestJson(
            '/auth/introspect',
            ['app_code' => $appCode],
            ['Authorization: Bearer ' . $consoleToken],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLauncherApps(string $consoleToken): ?array
    {
        $consoleToken = trim($consoleToken);
        if ($consoleToken === '') {
            return null;
        }

        $appCode = strtolower(trim((string) env('CONTROLLER_APP_CODE', 'engage')));

        return $this->requestJsonGet(
            '/auth/controller-apps/launcher',
            $appCode !== '' ? ['app_code' => $appCode] : [],
            $consoleToken,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSsoLaunchUrl(string $consoleToken, string $appCode): ?array
    {
        $consoleToken = trim($consoleToken);
        $appCode       = strtolower(trim($appCode));
        if ($consoleToken === '' || $appCode === '') {
            return null;
        }

        return $this->requestJsonGet(
            '/auth/sso/launch-url',
            ['app_code' => $appCode],
            $consoleToken,
        );
    }

    private function resolveConsoleApiBase(): string
    {
        foreach ([env('CONSOLE_API_URL'), env('CONSOLE_API_BASE_URL')] as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return rtrim($value, '/');
            }
        }

        return 'https://console.aicountly.org/api';
    }

    private function requestJson(string $path, array $payload, array $headers = []): ?array
    {
        $apiBase = $this->resolveConsoleApiBase();
        $body    = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init($apiBase . $path);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                $headers,
            ),
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            log_message('error', 'Console identity request failed HTTP ' . $status . ' for ' . $path);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || empty($decoded['success']) || ! is_array($decoded['data'] ?? null)) {
            return null;
        }

        return $decoded['data'];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>|null
     */
    private function requestJsonGet(string $path, array $query, string $bearerToken): ?array
    {
        $apiBase = $this->resolveConsoleApiBase();
        $url     = $apiBase . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            log_message('error', 'Console identity GET failed HTTP ' . $status . ' for ' . $path);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || empty($decoded['success']) || ! is_array($decoded['data'] ?? null)) {
            return null;
        }

        return $decoded['data'];
    }
}

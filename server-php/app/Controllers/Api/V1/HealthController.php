<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use Throwable;

class HealthController extends BaseApiController
{
    public function index()
    {
        $checks = [
            'jwt_secret'        => $this->flag(env('ENGAGE_JWT_SECRET', ''), 32),
            'service_key'       => $this->flag(env('ENGAGE_SERVICE_KEY', ''), 16),
            'console_key'       => $this->flag(env('CONSOLE_INBOUND_KEY', ''), 16),
            'reach_token'       => $this->flag(env('REACH_INBOUND_TOKEN', ''), 16),
            'db'                => 'unknown',
            'console_api_url'   => (string) env('CONSOLE_API_BASE_URL', '') !== '' ? 'ok' : 'empty',
            'worker_api_url'    => (string) env('WORKER_BASE_URL', '')      !== '' ? 'ok' : 'empty',
        ];

        try {
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            $checks['db'] = 'ok';
        } catch (Throwable $e) {
            $checks['db'] = 'error: ' . $e->getMessage();
        }

        $ok = $checks['jwt_secret'] === 'ok' && $checks['db'] === 'ok';

        return $this->ok([
            'service'   => 'aicountly-engage-api',
            'status'    => $ok ? 'ready' : 'degraded',
            'timestamp' => gmdate('c'),
            'checks'    => $checks,
        ]);
    }

    private function flag(string $val, int $minLength): string
    {
        if ($val === '') return 'empty';
        if (strlen($val) < $minLength) return 'too short';
        return 'ok';
    }
}

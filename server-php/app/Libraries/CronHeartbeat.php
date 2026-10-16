<?php
declare(strict_types=1);

namespace App\Libraries;

/**
 * Report a cron run to Console's Cron Job Monitor (console-react-app docs/CRON_MONITORS.md, migration 068):
 *
 *   POST {CONSOLE_API_BASE}/portal/cron-heartbeat     X-Console-Cron-Key: <CONSOLE_CRON_MONITOR_KEY>
 *   {monitor, run_id, outcome: ok|error, finished_at, message, counts}
 *
 * Monitor: engage.console_audit_deliver (`php spark engage:console-audit-deliver`, every 5 minutes: ok after a delivery run).
 *
 * The AICOUNTLY ops kit's protocol (bin/ops/heartbeat-lib.php), which this product does not vendor:
 *   - the key is CONSOLE_CRON_MONITOR_KEY, else CONSOLE_SERVICE_KEY (the values Console's CRON_MONITOR_KEY /
 *     CRON_MONITOR_KEY_HASH, or its CONSOLE_SERVICE_KEY, accept); the base is CONSOLE_API_BASE, else CONSOLE_API_URL,
 *     else https://console.aicountly.org/api;
 *   - UNCONFIGURED MEANS INERT: no key, no HTTP — a laptop must never hold production's monitor green;
 *   - NEVER BREAKS THE JOB: every failure is swallowed (one stderr line), 5 s budget;
 *   - counts only — never a company, ticket, address or payload.
 */
final class CronHeartbeat
{
    public const DEFAULT_BASE = 'https://console.aicountly.org/api';

    /** @var (callable(string, list<string>, string): int)|null test transport: (url, headers, json) → HTTP status */
    private static $transport = null;

    public static function setTransportForTesting(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /** @param array<string, int|float> $counts */
    public static function send(string $monitor, string $outcome, string $message = '', array $counts = []): bool
    {
        try {
            $env = static fn (string $k): string => trim((string) (getenv($k) ?: ($_ENV[$k] ?? '')));
            $key = $env('CONSOLE_CRON_MONITOR_KEY') ?: $env('CONSOLE_SERVICE_KEY');
            if ($key === '') {
                return false;
            }
            $base = rtrim($env('CONSOLE_API_BASE') ?: $env('CONSOLE_API_URL') ?: self::DEFAULT_BASE, '/');
            $now = time();
            $body = array_filter([
                'monitor'     => $monitor,
                'run_id'      => substr($monitor . '-' . gmdate('Ymd\THis\Z', $now) . '-' . getmypid(), 0, 120),
                'outcome'     => $outcome,
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'message'     => $message === '' ? null : substr($message, 0, 2000),
                'counts'      => $counts === [] ? null : array_slice($counts, 0, 50, true),
            ], static fn ($v) => $v !== null);
            $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
            $url = $base . '/portal/cron-heartbeat';
            $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Console-Cron-Key: ' . $key];

            if (self::$transport !== null) {
                $status = (int) (self::$transport)($url, $headers, $json);
            } else {
                $ch = function_exists('curl_init') ? curl_init($url) : false;
                if ($ch === false) {
                    return false;
                }
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $json,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
            }
            if ($status < 200 || $status >= 300) {
                fwrite(STDERR, "cron-heartbeat: Console answered HTTP {$status} for {$monitor} (the job itself is unaffected)\n");

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cron-heartbeat: ' . $e->getMessage() . " (the job itself is unaffected)\n");

            return false;
        }
    }
}

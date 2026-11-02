<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The AI model catalogue, read from Console (GET /portal/ai-models, capability ai.catalogue).
 *
 * Console owns which models exist, which provider each belongs to, whether a module is bound to
 * one, and what commercial tier it carries. Engage owns what each model's tokens cost. Engage
 * reads the list rather than keeping a copy, because two lists of models would drift and the one
 * that drifts is the one that prices things.
 *
 * NEVER FAILS LOUDLY. Console being unreachable must not take the Token Conversions screen down:
 * the rates are Engage's own data and stay readable and editable. The caller gets ok = false and
 * a reason to show, never an exception and never an empty list pretending to be an answer — "no
 * models" would invite someone to type them all in again.
 *
 * Cached briefly in memory for the request. The catalogue changes when a human registers a model,
 * so there is nothing to gain from a longer-lived cache and something to lose: a staff member who
 * has just added a model in Console expects to see it here on the next refresh.
 */
final class ConsoleModelCatalogue
{
    /** @var array{ok:bool, models:list<array<string,mixed>>, error:?string}|null */
    private static ?array $memo = null;

    public function __construct(private ?Client $http = null)
    {
    }

    /** @return array{ok:bool, models:list<array<string,mixed>>, error:?string} */
    public function models(): array
    {
        if (self::$memo !== null && $this->http === null) {
            return self::$memo;
        }

        $base = rtrim((string) env('CONSOLE_API_URL', (string) env('CONSOLE_API_BASE_URL', '')), '/');
        $token = (string) env('CONSOLE_API_TOKEN', '');
        if ($base === '' || $token === '') {
            return self::$memo = [
                'ok'     => false,
                'models' => [],
                'error'  => 'Console is not configured here (CONSOLE_API_URL and CONSOLE_API_TOKEN). '
                    . 'Rates can still be set; the model list and the missing-rate warnings need Console.',
            ];
        }

        try {
            $client = $this->http ?? new Client(['timeout' => 8]);
            $res = $client->request('GET', $base . '/portal/ai-models', [
                'headers'     => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            $json = json_decode((string) $res->getBody(), true);
            if ($status === 401 || $status === 403) {
                return self::$memo = [
                    'ok'     => false,
                    'models' => [],
                    'error'  => "Console refused Engage's key for the model catalogue (HTTP {$status}). "
                        . 'It needs the ai.catalogue capability — Console migration 082.',
                ];
            }
            if ($status < 200 || $status >= 300 || ! is_array($json)) {
                return self::$memo = ['ok' => false, 'models' => [], 'error' => "Console answered HTTP {$status} for the model catalogue."];
            }
            $models = $json['data']['models'] ?? $json['models'] ?? null;
            if (! is_array($models)) {
                return self::$memo = ['ok' => false, 'models' => [], 'error' => 'Console answered without a model list.'];
            }

            return self::$memo = ['ok' => true, 'models' => array_values($models), 'error' => null];
        } catch (GuzzleException $e) {
            log_message('warning', 'Token conversions: Console model catalogue unreadable: {m}', ['m' => $e->getMessage()]);

            return self::$memo = ['ok' => false, 'models' => [], 'error' => 'Console could not be reached for the model catalogue.'];
        }
    }

    /** Tests and long-running commands: forget what was read. */
    public static function forget(): void
    {
        self::$memo = null;
    }
}

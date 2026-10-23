<?php
declare(strict_types=1);

namespace App\Filters;

use App\Libraries\AicS2s\AicS2sConfig;
use App\Libraries\AicS2s\AicS2sResponse;
use App\Libraries\AicS2s\AicS2sSignature;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Inbound AIC-S2S v1 (commerce contract v1 §0.1–0.4) for one calling service. Reusable: the first route argument
 * names the client — `['filter' => 'aic-s2s:manage']`; configuration is AicS2sConfig (env). Further arguments name
 * the key scopes the route accepts — `'aic-s2s:myaicountly,issue'` — so a key of another scope gets 403
 * route_not_allowed_for_key even when its method would match. No scope argument = any scope whose methods match.
 *
 * Checks, in order (first failure answers, contract §0.2 error envelope):
 *   503 inbound_not_configured   no environment / no live key for this client (dormant by default)
 *   400 environment_required     X-AIC-Environment missing          403 environment_mismatch  not ours
 *   401 signature_missing        a required X-AIC-* header missing
 *   401 unknown_key              X-AIC-Client is not this client, or X-AIC-Key-Id is not live
 *   401 timestamp_stale          |now − X-AIC-Timestamp| > 300 s
 *   401 signature_invalid        HMAC mismatch (constant-time compare) or malformed nonce
 *   401 nonce_replayed           nonce already used by this key id in the last 600 s
 *   403 route_not_allowed_for_key  read key on a non-GET route, issue key on a non-POST route, or a scope the
 *                                  route does not accept
 *   429 rate_limited (+ Retry-After)  120 req/min per read key, 30/min per issue key, 60/min per quote key (or the client's
 *                                  ENGAGE_INBOUND_<CLIENT>_RATE_PER_MINUTE)
 *   503 database_unavailable     the nonce / rate tables could not be reached
 * On success $request->aicS2s = {client, key_id, scope, actor, request_id, environment}.
 * X-AIC-Actor is recorded for audit only — it never authorises anything.
 */
class AicS2sFilter implements FilterInterface
{
    public const MAX_SKEW_SECONDS = 300;
    public const NONCE_TTL_SECONDS = 600;
    public const RATE_LIMITS = AicS2sConfig::DEFAULT_RATE_LIMITS;

    public function before(RequestInterface $request, $arguments = null)
    {
        $args = array_values(array_map(static fn ($a) => strtolower(trim((string) $a)), (array) ($arguments ?? [])));
        $client = $args[0] ?? 'manage';
        $routeScopes = array_values(array_filter(array_slice($args, 1), static fn ($a) => $a !== ''));
        $rid = AicS2sResponse::requestId($request->getHeaderLine('X-AIC-Request-Id'));
        $res = service('response');
        $err = static fn (int $s, string $code, string $msg, bool $retry = false, array $d = []) => AicS2sResponse::error($res, $s, $code, $msg, $rid, $retry, $d);

        $cfg = AicS2sConfig::forClient($client);
        if (! $cfg->isConfigured()) {
            return $err(503, 'inbound_not_configured', 'This Engage endpoint is not configured for ' . $client . '.', true);
        }

        $env = strtolower(trim($request->getHeaderLine('X-AIC-Environment')));
        if ($env === '') {
            return $err(400, 'environment_required', 'X-AIC-Environment is required.');
        }
        if ($env !== $cfg->environment) {
            return $err(403, 'environment_mismatch', 'X-AIC-Environment does not match this Engage environment.');
        }

        $h = [];
        foreach (['X-AIC-Client', 'X-AIC-Key-Id', 'X-AIC-Timestamp', 'X-AIC-Nonce', 'X-AIC-Signature'] as $name) {
            $h[$name] = trim($request->getHeaderLine($name));
            if ($h[$name] === '') {
                return $err(401, 'signature_missing', $name . ' is required.', false, ['header' => $name]);
            }
        }
        if (strtolower($h['X-AIC-Client']) !== $cfg->client) {
            return $err(401, 'unknown_key', 'Unknown client or key id.');
        }
        $keyId = $h['X-AIC-Key-Id'];
        $key = $cfg->keys[$keyId] ?? null;
        if ($key === null) {
            return $err(401, 'unknown_key', 'Unknown client or key id.');
        }

        $ts = $h['X-AIC-Timestamp'];
        if (! preg_match('/\A\d{1,12}\z/', $ts) || abs(time() - (int) $ts) > self::MAX_SKEW_SECONDS) {
            return $err(401, 'timestamp_stale', 'X-AIC-Timestamp is outside the allowed window (±300 s).');
        }

        $nonce = $h['X-AIC-Nonce'];
        $method = strtoupper($request->getMethod());
        $path = method_exists($request, 'getPath') ? (string) $request->getPath() : $request->getUri()->getPath();
        $canonical = AicS2sSignature::canonical($method, $path, (string) $request->getUri()->getQuery(), $ts, $nonce, (string) $request->getBody());
        if (! preg_match('/\A[A-Za-z0-9\-]{16,64}\z/', $nonce) || ! AicS2sSignature::verify($key['secret'], $canonical, $h['X-AIC-Signature'])) {
            return $err(401, 'signature_invalid', 'The request signature is not valid.');
        }

        $scopeOk = in_array($method, AicS2sConfig::methodsFor($key['scope']), true)
            && ($routeScopes === [] || in_array($key['scope'], $routeScopes, true));

        try {
            $db = Database::connect();
            $db->query("DELETE FROM engage_s2s_nonces WHERE seen_at < NOW() - INTERVAL '" . self::NONCE_TTL_SECONDS . " seconds'");
            $fresh = $db->query(
                'INSERT INTO engage_s2s_nonces (key_id, nonce) VALUES (?, ?) ON CONFLICT (key_id, nonce) DO NOTHING RETURNING key_id',
                [$keyId, $nonce]
            )->getRowArray();
            if (! $fresh) {
                return $err(401, 'nonce_replayed', 'This X-AIC-Nonce was already used.');
            }
            if (! $scopeOk) {
                return $err(403, 'route_not_allowed_for_key', 'This key may not call this route.', false, ['scope' => $key['scope']]);
            }
            $hits = (int) ($db->query(
                "INSERT INTO engage_s2s_rate (key_id, window_start, hits) VALUES (?, date_trunc('minute', NOW()), 1)
                 ON CONFLICT (key_id, window_start) DO UPDATE SET hits = engage_s2s_rate.hits + 1 RETURNING hits",
                [$keyId]
            )->getRow()->hits ?? 0);
            if (random_int(1, 50) === 1) {
                $db->query("DELETE FROM engage_s2s_rate WHERE window_start < NOW() - INTERVAL '10 minutes'");
            }
        } catch (\Throwable $e) {
            log_message('error', 'AIC-S2S nonce/rate store failed: ' . $e->getMessage());

            return $err(503, 'database_unavailable', 'Engage is temporarily unavailable.', true);
        }
        $limit = $cfg->rateLimit($key['scope']);
        if ($hits > $limit) {
            $retryAfter = 60 - (time() % 60);

            return $err(429, 'rate_limited', 'Too many requests for this key. Retry later.', true, ['limit_per_minute' => $limit])
                ->setHeader('Retry-After', (string) max(1, $retryAfter));
        }

        $actor = trim($request->getHeaderLine('X-AIC-Actor'));
        $actor = preg_match('/\A[A-Za-z0-9._:@\-]{1,128}\z/', $actor) ? $actor : 'unknown';
        $request->aicS2s = [
            'client'      => $cfg->client,
            'key_id'      => $keyId,
            'scope'       => $key['scope'],
            'actor'       => $actor,
            'request_id'  => $rid,
            'environment' => $cfg->environment,
        ];

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

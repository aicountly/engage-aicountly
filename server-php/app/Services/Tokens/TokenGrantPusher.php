<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use App\Libraries\AicS2s\AicS2sSignature;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Delivers queued token grants to AI Pulse (POST /v1/inbound/engage/token-grants, AIC-S2S v1).
 *
 * Grants are queued rather than pushed inline because they are value a customer has either
 * paid for or been promised: the moment of redemption must not depend on Pulse being
 * reachable, and a push that times out must be safe to repeat. Pulse is idempotent on
 * `grant_ref`, so a retry credits once and answers `duplicate: true`.
 *
 * Back-off doubles from a minute and caps at six hours. After 12 attempts a grant is `dead`
 * and stops being retried — at that point something is wrong that retrying will not fix, and
 * a human needs to look rather than the job spinning forever. A dead grant is still a row
 * naming a customer who is owed tokens; nothing is lost, it is waiting.
 */
final class TokenGrantPusher
{
    public const PATH = '/v1/inbound/engage/token-grants';
    public const MAX_ATTEMPTS = 12;

    public function __construct(
        private ?BaseConnection $db = null,
        private readonly ?Client $http = null,
    ) {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /**
     * Send what is due.
     *
     * @return array{sent:int, failed:int, dead:int, pending:int, configured:bool}
     */
    public function drain(int $limit = 200): array
    {
        $base = rtrim((string) env('PULSE_API_BASE_URL', ''), '/');
        $keyId = (string) env('ENGAGE_OUTBOUND_PULSE_KEY_ID', '');
        $secret = (string) env('ENGAGE_OUTBOUND_PULSE_SECRET', '');
        $environment = (string) env('ENGAGE_S2S_ENVIRONMENT', 'production');

        $pending = (int) ($this->db()->query("SELECT COUNT(*) AS n FROM engage_token_grants WHERE status = 'pending'")->getRowArray()['n'] ?? 0);
        if ($base === '' || $keyId === '' || strlen($secret) < 32) {
            // Nothing is lost and nothing is retried into a wall: the rows wait.
            return ['sent' => 0, 'failed' => 0, 'dead' => 0, 'pending' => $pending, 'configured' => false];
        }

        $due = $this->db()->query(
            "SELECT * FROM engage_token_grants
              WHERE status = 'pending' AND next_attempt_at <= NOW()
              ORDER BY id LIMIT ?",
            [max(1, min(1000, $limit))]
        )->getResultArray();

        $sent = $failed = $dead = 0;
        foreach ($due as $g) {
            $r = $this->deliver($base, $keyId, $secret, $environment, $g);
            if ($r === true) {
                $sent++;
                continue;
            }
            $attempts = (int) $g['attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $dead++;
            } else {
                $failed++;
            }
        }

        $pending = (int) ($this->db()->query("SELECT COUNT(*) AS n FROM engage_token_grants WHERE status = 'pending'")->getRowArray()['n'] ?? 0);

        return ['sent' => $sent, 'failed' => $failed, 'dead' => $dead, 'pending' => $pending, 'configured' => true];
    }

    /** @param array<string,mixed> $g */
    private function deliver(string $base, string $keyId, string $secret, string $environment, array $g): bool
    {
        // Encoded once: the exact bytes are both signed and sent, because re-encoding
        // between the two is how a signature stops matching.
        $body = json_encode([
            'holder_uuid' => (string) $g['holder_uuid'],
            'tokens'      => (int) $g['tokens'],
            'grant_ref'   => (string) $g['grant_ref'],
            'reason'      => (string) $g['reason'],
        ], JSON_UNESCAPED_SLASHES);

        $headers = AicS2sSignature::headers('engage', $keyId, $secret, $environment, 'POST', self::PATH, '', $body, 'service');

        try {
            $res = ($this->http ?? new Client(['timeout' => 15]))->request('POST', $base . '/api' . self::PATH, [
                'headers'     => $headers + ['Content-Type' => 'application/json'],
                'body'        => $body,
                'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            if ($status >= 200 && $status < 300) {
                // Pulse answers duplicate:true for a grant it already credited. That is a
                // success, not a problem — it is exactly what idempotency is for.
                $this->db()->query(
                    "UPDATE engage_token_grants SET status = 'delivered', delivered_at = NOW(), attempts = attempts + 1,
                            last_error = NULL, updated_at = NOW() WHERE id = ?",
                    [(int) $g['id']]
                );

                return true;
            }
            $json = json_decode((string) $res->getBody(), true);
            $why = is_array($json) ? (string) ($json['message'] ?? $json['code'] ?? '') : '';
            $this->retry($g, "HTTP {$status}" . ($why !== '' ? ": {$why}" : ''));

            return false;
        } catch (GuzzleException $e) {
            $this->retry($g, 'unreachable: ' . $e->getMessage());

            return false;
        }
    }

    /** @param array<string,mixed> $g */
    private function retry(array $g, string $error): void
    {
        $attempts = (int) $g['attempts'] + 1;
        $dead = $attempts >= self::MAX_ATTEMPTS;
        // 1, 2, 4 … minutes, capped at six hours.
        $delay = min(21600, 60 * (2 ** min(8, $attempts - 1)));

        $this->db()->query(
            'UPDATE engage_token_grants
                SET status = ?, attempts = ?, last_error = ?, next_attempt_at = NOW() + (? || \' seconds\')::interval, updated_at = NOW()
              WHERE id = ?',
            [$dead ? 'dead' : 'pending', $attempts, mb_substr($error, 0, 500), $delay, (int) $g['id']]
        );

        log_message($dead ? 'error' : 'warning', 'token grant {ref} not delivered (attempt {n}): {e}', [
            'ref' => $g['grant_ref'], 'n' => $attempts, 'e' => $error,
        ]);
    }

    /** Put a dead grant back in the queue, once someone has fixed whatever stopped it. */
    public function requeue(int $id): bool
    {
        $this->db()->query(
            "UPDATE engage_token_grants SET status = 'pending', attempts = 0, next_attempt_at = NOW(), last_error = NULL, updated_at = NOW()
              WHERE id = ? AND status = 'dead'",
            [$id]
        );

        return $this->db()->affectedRows() > 0;
    }
}

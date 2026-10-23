<?php

declare(strict_types=1);

namespace App\Libraries\Email;

require_once dirname(__DIR__) . '/EmailTransport/SmtpClient.php';

/**
 * Registry provider of type "smtp" (config host, port, encryption tls|ssl|none, username, timeout?;
 * secret = password) → Console's SmtpClient (app/Libraries/EmailTransport/SmtpClient.php — copied
 * verbatim from console-react-app; re-copy, never edit).
 */
final class SmtpProviderTransport
{
    /** @param array<string,mixed> $overrides test seam merged into the SmtpClient config (e.g. helo, verify_peer) */
    public function __construct(private ?\SmtpClientConnection $connection = null, private array $overrides = [])
    {
    }

    /**
     * @param array{code:string, config:array<string,mixed>, secret:string} $provider
     * @param array<string,mixed> $msg
     * @return array{ok:bool, id:?string, error:?string, mode:string, url:string}
     */
    public function send(array $provider, array $msg): array
    {
        $c      = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $client = new \SmtpClient($this->overrides + [
            'host'       => (string) ($c['host'] ?? ''),
            'port'       => (int) ($c['port'] ?? 0),
            'encryption' => (string) ($c['encryption'] ?? 'tls'),
            'username'   => (string) ($c['username'] ?? ''),
            'password'   => (string) ($provider['secret'] ?? ''),
            'timeout'    => (float) ($c['timeout'] ?? 20) ?: 20,
        ], $this->connection);
        $r = $client->send($msg);

        return ['ok' => (bool) $r['ok'], 'id' => $r['message_id'] ?? null, 'error' => $r['error'] ?? null, 'mode' => 'smtp', 'url' => (string) ($r['url'] ?? '')];
    }
}

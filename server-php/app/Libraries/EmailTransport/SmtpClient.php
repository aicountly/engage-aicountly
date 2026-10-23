<?php
declare(strict_types=1);

/**
 * SmtpClient — a small, self-contained SMTP submission client.
 *
 * ONE FILE, NO DEPENDENCIES: no framework, no Composer, no namespace, nothing but PHP 8.1+ with
 * openssl (for TLS). Console (console.aicountly.org) owns it; the products (Flow, Reach, Engage, …)
 * copy this file verbatim, so keep it free of anything Console-specific. Origin:
 * console-react-app server-php/app/Libraries/EmailTransport/SmtpClient.php (docs/EMAIL_PROVIDERS.md).
 *
 * What it does
 *   - connect: plain TCP (`none`), STARTTLS on a plain connection (`tls`, usually port 587) or
 *     implicit TLS from the first byte (`ssl`, usually port 465). Certificates are verified
 *     (peer and name) unless `verify_peer` is explicitly false; `cafile` names extra trust.
 *   - EHLO (HELO if the server refuses EHLO), STARTTLS then EHLO again; with `tls` a server that
 *     does not offer STARTTLS is refused — never a silent downgrade to clear text.
 *   - AUTH PLAIN (preferred) or AUTH LOGIN. Credentials are never sent over an unencrypted
 *     connection except to a loopback host (or with `allow_plaintext_auth` => true).
 *   - MAIL FROM / RCPT TO (every recipient must be accepted, or nothing is sent) / DATA, then QUIT.
 *   - MIME: multipart/alternative text + HTML (either part alone works too), UTF-8,
 *     quoted-printable bodies, RFC 2047 encoded subject and display names, Message-ID, Date.
 *   - dot-stuffing, CRLF normalisation, header-injection safe (CR/LF stripped from every header
 *     value, addresses validated).
 *   - timeouts on connect and on every read/write (`timeout`, seconds, default 15).
 *
 * What it answers
 *   send() never throws for a delivery problem; it returns
 *     ['ok' => bool, 'message_id' => ?string, 'error' => ?string, 'code' => int (last SMTP code),
 *      'transient' => bool (4xx / network), 'response' => string (server's last answer),
 *      'mode' => 'smtp', 'url' => 'smtp://host:port']
 *   ok is true only when the server answered 250 to the end of DATA, i.e. it accepted the message.
 *   No password (or its base64) ever appears in error, response or transcript().
 *
 * Usage
 *   $smtp = new SmtpClient(['host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
 *                           'username' => 'mailer@example.com', 'password' => '…']);
 *   $r = $smtp->send(['from_email' => 'noreply@example.com', 'from_name' => 'Example',
 *                     'to' => 'person@example.org', 'reply_to' => 'support@example.com',
 *                     'subject' => 'Hello', 'html' => '<p>Hi</p>', 'text' => 'Hi']);
 *
 * Tests: inject an SmtpClientConnection (a fake socket) as the second constructor argument.
 */

final class SmtpClientException extends RuntimeException
{
    public function __construct(string $message, private readonly int $smtpCode = 0, private readonly bool $transient = false)
    {
        parent::__construct($message);
    }

    public function smtpCode(): int
    {
        return $this->smtpCode;
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}

/** The socket SmtpClient talks through. SmtpClientStreamConnection is the real one; tests pass a fake. */
interface SmtpClientConnection
{
    /**
     * @param bool $implicitTls TLS from the first byte (`ssl` encryption)
     * @param array<string, mixed> $tls ssl stream-context options (peer_name, verify_peer, cafile, …)
     */
    public function open(string $host, int $port, bool $implicitTls, float $timeout, array $tls): void;

    /** One line from the server without its CRLF; throws SmtpClientException on timeout or EOF. */
    public function readLine(): string;

    public function write(string $data): void;

    /** Upgrade the open connection to TLS (after a 220 answer to STARTTLS). */
    public function startTls(array $tls): void;

    public function close(): void;
}

final class SmtpClientStreamConnection implements SmtpClientConnection
{
    /** @var resource|null */
    private $stream = null;
    private float $timeout = 15.0;

    public function open(string $host, int $port, bool $implicitTls, float $timeout, array $tls): void
    {
        $this->timeout = $timeout;
        $context = stream_context_create(['ssl' => $tls]);
        $target = (str_contains($host, ':') && !str_starts_with($host, '[')) ? "[{$host}]" : $host;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            ($implicitTls ? 'tls://' : 'tcp://') . $target . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            $why = trim($errstr) !== '' ? trim($errstr) : ('error ' . $errno);
            throw new SmtpClientException("could not connect to {$host}:{$port} ({$why})", 0, true);
        }
        $this->stream = $stream;
        $this->applyTimeout();
    }

    public function readLine(): string
    {
        if ($this->stream === null) {
            throw new SmtpClientException('connection is not open', 0, true);
        }
        $line = fgets($this->stream, 4096);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            if (!empty($meta['timed_out'])) {
                throw new SmtpClientException('timed out waiting for the server', 0, true);
            }
            throw new SmtpClientException('the server closed the connection', 0, true);
        }

        return rtrim($line, "\r\n");
    }

    public function write(string $data): void
    {
        if ($this->stream === null) {
            throw new SmtpClientException('connection is not open', 0, true);
        }
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $n) {
            $n = @fwrite($this->stream, substr($data, $written, 65536));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($this->stream);
                throw new SmtpClientException(!empty($meta['timed_out']) ? 'timed out sending to the server' : 'could not write to the server', 0, true);
            }
        }
    }

    public function startTls(array $tls): void
    {
        if ($this->stream === null) {
            throw new SmtpClientException('connection is not open', 0, true);
        }
        foreach ($tls as $option => $value) {
            stream_context_set_option($this->stream, 'ssl', (string) $option, $value);
        }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        $ok = @stream_socket_enable_crypto($this->stream, true, $method);
        if ($ok !== true) {
            $error = error_get_last();
            $detail = is_array($error) ? preg_replace('/^stream_socket_enable_crypto\(\): /', '', (string) $error['message']) : '';
            throw new SmtpClientException('TLS negotiation failed' . ($detail !== '' ? " ({$detail})" : ''), 0, true);
        }
        $this->applyTimeout();
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    private function applyTimeout(): void
    {
        if ($this->stream !== null) {
            $seconds = (int) floor($this->timeout);
            stream_set_timeout($this->stream, $seconds, (int) (($this->timeout - $seconds) * 1_000_000));
        }
    }
}

final class SmtpClient
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];
    private const DEFAULT_PORTS = ['tls' => 587, 'ssl' => 465, 'none' => 25];
    private const MAX_REPLY_LINES = 100;

    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private float $timeout;
    private string $helo;
    private bool $allowPlaintextAuth;
    /** @var array<string, mixed> */
    private array $tls;
    private SmtpClientConnection $connection;

    /** @var array<string, string> EHLO keywords (upper case) => their parameters */
    private array $extensions = [];
    private bool $encrypted = false;
    /** @var list<string> */
    private array $transcript = [];
    private int $lastCode = 0;
    private string $lastResponse = '';

    /**
     * @param array{host: string, port?: int|string, encryption?: string, username?: string, password?: string,
     *              timeout?: int|float, helo?: string, verify_peer?: bool, cafile?: string, allow_plaintext_auth?: bool} $config
     */
    public function __construct(array $config, ?SmtpClientConnection $connection = null)
    {
        $this->host = strtolower(trim((string) ($config['host'] ?? '')));
        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        $this->encryption = in_array($encryption, self::ENCRYPTIONS, true) ? $encryption : 'tls';
        $port = (int) ($config['port'] ?? 0);
        $this->port = $port > 0 && $port <= 65535 ? $port : self::DEFAULT_PORTS[$this->encryption];
        $this->username = (string) ($config['username'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $timeout = (float) ($config['timeout'] ?? 15);
        $this->timeout = $timeout > 0 ? $timeout : 15.0;
        $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($config['helo'] ?? (gethostname() ?: ''))) ?? '';
        $this->helo = $helo !== '' ? $helo : 'localhost';
        $this->allowPlaintextAuth = ($config['allow_plaintext_auth'] ?? false) === true;
        $verify = ($config['verify_peer'] ?? true) !== false;
        $this->tls = [
            'peer_name'         => $this->host,
            'SNI_enabled'       => true,
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => false,
        ];
        if (is_string($config['cafile'] ?? null) && $config['cafile'] !== '') {
            $this->tls['cafile'] = $config['cafile'];
        }
        $this->connection = $connection ?? new SmtpClientStreamConnection();
    }

    /**
     * Send one message. See the file header for the answer's shape.
     *
     * @param array{from_email: string, from_name?: string, to: string|list<string|array{email: string, name?: string}>,
     *              reply_to?: string, subject: string, html?: string, text?: string, headers?: array<string, string>,
     *              message_id?: string} $message
     * @return array{ok: bool, message_id: ?string, error: ?string, code: int, transient: bool, response: string, mode: string, url: string}
     */
    public function send(array $message): array
    {
        $this->transcript = [];
        $this->extensions = [];
        $this->encrypted = false;
        $this->lastCode = 0;
        $this->lastResponse = '';
        $url = 'smtp://' . $this->host . ':' . $this->port;
        $messageId = null;
        $opened = false;

        try {
            if ($this->host === '' || preg_match('/^[a-z0-9.\-:\[\]]+$/', $this->host) !== 1) {
                throw new SmtpClientException('no valid SMTP host is configured');
            }
            $from = self::address((string) ($message['from_email'] ?? ''), 'from_email');
            $recipients = self::recipients($message['to'] ?? []);
            $built = self::buildMessage($message, $recipients);
            $messageId = $built['message_id'];

            $this->connection->open($this->host, $this->port, $this->encryption === 'ssl', $this->timeout, $this->tls);
            $opened = true;
            $this->encrypted = $this->encryption === 'ssl';
            $this->expect('connect', [220]);
            $this->hello();

            if ($this->encryption === 'tls') {
                if (!isset($this->extensions['STARTTLS'])) {
                    throw new SmtpClientException('the server does not offer STARTTLS; refusing to send without encryption (encryption = tls)');
                }
                $this->command('STARTTLS', 'STARTTLS', [220]);
                $this->connection->startTls($this->tls);
                $this->encrypted = true;
                $this->transcript[] = '-- TLS established';
                $this->hello();
            }

            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $from . '>', 'MAIL FROM', [250]);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt['email'] . '>', 'RCPT TO', [250, 251]);
            }
            $this->command('DATA', 'DATA', [354]);
            $data = self::dotStuff($built['data']);
            $this->connection->write($data . ".\r\n");
            $this->transcript[] = 'C: <message, ' . strlen($data) . ' bytes>';
            $this->transcript[] = 'C: .';
            $this->expect('end of DATA', [250]);
            $accepted = $this->lastResponse;

            try {
                $this->command('QUIT', 'QUIT', [221]);
            } catch (SmtpClientException) {
                // The message is already accepted; a rude goodbye changes nothing.
            }

            return [
                'ok'         => true,
                'message_id' => $messageId,
                'error'      => null,
                'code'       => 250,
                'transient'  => false,
                'response'   => $this->redact($accepted),
                'mode'       => 'smtp',
                'url'        => $url,
            ];
        } catch (SmtpClientException $e) {
            if ($opened) {
                try {
                    $this->connection->write("QUIT\r\n");
                } catch (SmtpClientException) {
                    // best effort
                }
            }

            return [
                'ok'         => false,
                'message_id' => null,
                'error'      => $this->redact($e->getMessage()),
                'code'       => $e->smtpCode(),
                'transient'  => $e->isTransient(),
                'response'   => $this->redact($this->lastResponse),
                'mode'       => 'smtp',
                'url'        => $url,
            ];
        } finally {
            if ($opened) {
                $this->connection->close();
            }
        }
    }

    /** @return list<string> the conversation, credentials and message body left out */
    public function transcript(): array
    {
        return array_map(fn (string $l): string => $this->redact($l), $this->transcript);
    }

    /**
     * The message as it goes after DATA (before dot-stuffing): headers, blank line, body, CRLF line ends.
     *
     * @param array<string, mixed> $message
     * @param list<array{email: string, name: string}>|null $recipients
     * @return array{data: string, message_id: string}
     */
    public static function buildMessage(array $message, ?array $recipients = null, ?int $time = null): array
    {
        $fromEmail = self::address((string) ($message['from_email'] ?? ''), 'from_email');
        $recipients ??= self::recipients($message['to'] ?? []);
        $domain = substr($fromEmail, strrpos($fromEmail, '@') + 1);
        $messageId = trim((string) ($message['message_id'] ?? ''), " <>\r\n");
        if ($messageId === '' || preg_match('/^[^\s<>@]+@[^\s<>@]+$/', $messageId) !== 1) {
            $messageId = bin2hex(random_bytes(16)) . '@' . $domain;
        }

        $headers = [
            'Date'         => date('D, d M Y H:i:s O', $time ?? time()),
            'From'         => self::formatAddress($fromEmail, (string) ($message['from_name'] ?? '')),
            'To'           => implode(', ', array_map(static fn (array $r): string => self::formatAddress($r['email'], $r['name']), $recipients)),
        ];
        $replyTo = trim((string) ($message['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $headers['Reply-To'] = self::formatAddress(self::address($replyTo, 'reply_to'));
        }
        $headers['Subject'] = self::encodeHeader((string) ($message['subject'] ?? ''));
        $headers['Message-ID'] = '<' . $messageId . '>';
        $headers['MIME-Version'] = '1.0';

        $reserved = array_map('strtolower', array_merge(array_keys($headers), ['Content-Type', 'Content-Transfer-Encoding', 'Bcc', 'Cc']));
        foreach ((array) ($message['headers'] ?? []) as $name => $value) {
            $name = (string) $name;
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]{0,62}$/', $name) !== 1 || in_array(strtolower($name), $reserved, true)) {
                continue;
            }
            $headers[$name] = self::encodeHeader((string) $value);
        }

        $html = (string) ($message['html'] ?? '');
        $text = (string) ($message['text'] ?? '');
        if ($html === '' && $text === '') {
            throw new SmtpClientException('the message has no body');
        }

        $part = static fn (string $type, string $content): string => "Content-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::quotedPrintable($content) . "\r\n";

        if ($html !== '' && $text !== '') {
            $boundary = '=_alt_' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "This is a multi-part message in MIME format.\r\n\r\n"
                . '--' . $boundary . "\r\n" . $part('text/plain', $text)
                . '--' . $boundary . "\r\n" . $part('text/html', $html)
                . '--' . $boundary . "--\r\n";
        } else {
            $headers['Content-Type'] = ($html !== '' ? 'text/html' : 'text/plain') . '; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
            $body = self::quotedPrintable($html !== '' ? $html : $text) . "\r\n";
        }

        $head = '';
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }

        return ['data' => $head . "\r\n" . $body, 'message_id' => $messageId];
    }

    /** CRLF line ends and a second dot on every line that starts with one (RFC 5321 4.5.2). */
    public static function dotStuff(string $data): string
    {
        $data = self::crlf($data);
        if ($data !== '' && !str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }

        return preg_replace('/^\./m', '..', $data) ?? $data;
    }

    /** A header value: CR/LF removed; RFC 2047 (UTF-8, base64) words when it is not plain ASCII. */
    public static function encodeHeader(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1 && strlen($value) <= 900) {
            return $value;
        }
        if (!preg_match('//u', $value)) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = [];
        $chunk = '';
        foreach ($chars as $char) {
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }

    public static function formatAddress(string $email, string $name = ''): string
    {
        $name = trim(preg_replace('/[\r\n\t]+/', ' ', $name) ?? '');
        if ($name === '') {
            return $email;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            return '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
        }

        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    public static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[] '));

        return $host === 'localhost' || $host === '::1' || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
    }

    // ------------------------------------------------------------------ protocol

    private function hello(): void
    {
        $this->extensions = [];
        $this->writeLine('EHLO ' . $this->helo);
        [$code, $lines] = $this->readReply();
        if ($code === 250) {
            foreach (array_slice($lines, 1) as $line) {
                $parts = preg_split('/[\s=]+/', trim($line), 2) ?: [];
                $keyword = strtoupper($parts[0] ?? '');
                if ($keyword !== '') {
                    $this->extensions[$keyword] = strtoupper(trim($parts[1] ?? ''));
                }
            }

            return;
        }
        if ($code >= 500) {
            // An old server without ESMTP: HELO, and no extensions (so no STARTTLS, no AUTH).
            $this->command('HELO ' . $this->helo, 'HELO', [250]);

            return;
        }
        throw $this->failure('EHLO', $code, $lines);
    }

    private function authenticate(): void
    {
        if (!$this->encrypted && !$this->allowPlaintextAuth && !self::isLoopback($this->host)) {
            throw new SmtpClientException('refusing to send the SMTP password over an unencrypted connection (use encryption tls or ssl)');
        }
        $offered = preg_split('/\s+/', $this->extensions['AUTH'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array('PLAIN', $offered, true)) {
            $this->command(
                'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password),
                'AUTH',
                [235],
                'AUTH PLAIN <credentials>'
            );

            return;
        }
        if (in_array('LOGIN', $offered, true)) {
            $this->command('AUTH LOGIN', 'AUTH', [334]);
            $this->command(base64_encode($this->username), 'AUTH (username)', [334], '<username>');
            $this->command(base64_encode($this->password), 'AUTH', [235], '<password>');

            return;
        }
        throw new SmtpClientException(
            $offered === []
                ? 'the server does not offer SMTP authentication'
                : 'the server offers no supported AUTH mechanism (offers ' . implode(' ', $offered) . '; supported: PLAIN, LOGIN)'
        );
    }

    /**
     * @param list<int> $accept
     */
    private function command(string $line, string $stage, array $accept, ?string $logAs = null): void
    {
        $this->writeLine($line, $logAs);
        $this->expect($stage, $accept);
    }

    /** @param list<int> $accept */
    private function expect(string $stage, array $accept): void
    {
        [$code, $lines] = $this->readReply();
        if (!in_array($code, $accept, true)) {
            throw $this->failure($stage, $code, $lines);
        }
    }

    /** @param list<string> $lines */
    private function failure(string $stage, int $code, array $lines): SmtpClientException
    {
        $text = trim(implode(' ', $lines));

        return new SmtpClientException("SMTP {$code} at {$stage}" . ($text !== '' ? ": {$text}" : ''), $code, $code >= 400 && $code < 500);
    }

    private function writeLine(string $line, ?string $logAs = null): void
    {
        if (preg_match('/[\r\n]/', $line) === 1) {
            throw new SmtpClientException('refusing to send a command containing a line break');
        }
        $this->transcript[] = 'C: ' . ($logAs ?? $line);
        $this->connection->write($line . "\r\n");
    }

    /** @return array{0: int, 1: list<string>} the code and the text of each line */
    private function readReply(): array
    {
        $lines = [];
        $code = 0;
        for ($i = 0; $i < self::MAX_REPLY_LINES; $i++) {
            $line = $this->connection->readLine();
            $this->transcript[] = 'S: ' . $line;
            if (preg_match('/^(\d{3})([ -]?)(.*)$/s', $line, $m) !== 1) {
                throw new SmtpClientException('the server sent something that is not an SMTP reply: ' . substr($line, 0, 120), 0, true);
            }
            $code = (int) $m[1];
            $lines[] = $m[3];
            if ($m[2] !== '-') {
                $this->lastCode = $code;
                $this->lastResponse = $code . ' ' . trim(implode(' ', $lines));

                return [$code, $lines];
            }
        }
        throw new SmtpClientException('the server sent a reply of more than ' . self::MAX_REPLY_LINES . ' lines', 0, true);
    }

    // ------------------------------------------------------------------ helpers

    private function redact(string $text): string
    {
        $secrets = [];
        if ($this->password !== '') {
            $secrets[] = $this->password;
            $secrets[] = base64_encode($this->password);
            $secrets[] = base64_encode("\0" . $this->username . "\0" . $this->password);
        }
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '****', $text);
        }

        return $text;
    }

    private static function address(string $email, string $field): string
    {
        $email = trim($email);
        if ($email === '' || preg_match('/[\r\n<>,;\s]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SmtpClientException("{$field} is not a valid e-mail address");
        }

        return $email;
    }

    /** @return list<array{email: string, name: string}> */
    private static function recipients(mixed $to): array
    {
        $list = is_array($to) ? $to : array_filter(array_map('trim', explode(',', (string) $to)), static fn (string $s): bool => $s !== '');
        $out = [];
        foreach ($list as $entry) {
            $email = is_array($entry) ? (string) ($entry['email'] ?? '') : (string) $entry;
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            $out[] = ['email' => self::address($email, 'to'), 'name' => $name];
        }
        if ($out === []) {
            throw new SmtpClientException('the message has no recipient');
        }

        return $out;
    }

    private static function crlf(string $text): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $text) ?? $text;
    }

    private static function quotedPrintable(string $text): string
    {
        return quoted_printable_encode(self::crlf($text));
    }
}

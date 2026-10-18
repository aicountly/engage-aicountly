<?php
declare(strict_types=1);

namespace App\Libraries\Seller;

use RuntimeException;

/**
 * Encryption at rest for seller KYC numbers (PAN, GSTIN, bank account) and KYC documents.
 *
 * Key: ENGAGE_DATA_KEY — 32 random bytes, given as 64 hex characters or base64
 * (generate: php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"). Without a valid key nothing
 * sensitive can be stored: KYC submission and document upload answer 503 (fail closed).
 * ENGAGE_DATA_KEY_PREVIOUS (optional, same format) is tried for decryption after a key rotation.
 *
 * Format: "v1:" . base64(nonce(24) . XSalsa20-Poly1305 ciphertext) — libsodium secretbox.
 */
final class SellerCrypto
{
    private const PREFIX = 'v1:';

    private ?string $key;
    private ?string $previous;

    public function __construct(?string $key = null, ?string $previous = null)
    {
        $this->key      = self::parseKey($key ?? (string) env('ENGAGE_DATA_KEY', ''));
        $this->previous = self::parseKey($previous ?? (string) env('ENGAGE_DATA_KEY_PREVIOUS', ''));
    }

    public function isConfigured(): bool
    {
        return $this->key !== null && function_exists('sodium_crypto_secretbox');
    }

    public function encrypt(string $plain): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ENGAGE_DATA_KEY is not configured (32 bytes as hex or base64).');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, (string) $this->key));
    }

    public function decrypt(string $stored): string
    {
        if (! str_starts_with($stored, self::PREFIX)) {
            throw new RuntimeException('Unknown ciphertext format.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Corrupt ciphertext.');
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        foreach ([$this->key, $this->previous] as $k) {
            if ($k === null) {
                continue;
            }
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $k);
            if ($plain !== false) {
                return $plain;
            }
        }
        throw new RuntimeException('Could not decrypt with ENGAGE_DATA_KEY (or ENGAGE_DATA_KEY_PREVIOUS).');
    }

    /** "••••••1234" — only the last four characters are ever shown. */
    public static function mask(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $last = substr($value, -$visible);

        return str_repeat('•', max(4, strlen($value) - $visible)) . $last;
    }

    public static function maskLast4(?string $last4): ?string
    {
        return $last4 === null || $last4 === '' ? null : '••••••' . $last4;
    }

    private static function parseKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $raw)) {
            return (string) hex2bin($raw);
        }
        $b = base64_decode($raw, true);
        if ($b !== false && strlen($b) === 32) {
            return $b;
        }

        return null;
    }
}

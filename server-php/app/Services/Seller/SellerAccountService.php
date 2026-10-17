<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Seller accounts: lookup, the public "me" view, sessions, one-time tokens, password policy and
 * status changes. Shared by the seller-portal API (SellerAuthService) and the staff screens
 * (SellerAdminService).
 *
 * Sessions: opaque random 32-byte token (hex), only sha256 stored in engage_seller_sessions;
 * 8 hours idle, 7 days absolute; revoked on suspend / terminate / password change / reset.
 * Tokens (engage_seller_tokens): activation 72 h, password reset 60 min, login OTP 10 min — single use,
 * only the sha256 of the token (and a password hash of an OTP code) stored.
 */
class SellerAccountService
{
    public const SESSION_IDLE_SECONDS     = 8 * 3600;
    public const SESSION_ABSOLUTE_SECONDS = 7 * 86400;
    public const ACTIVATION_SECONDS       = 72 * 3600;
    public const RESET_SECONDS            = 3600;
    public const STAFF_RESET_SECONDS      = 24 * 3600;
    public const OTP_SECONDS              = 600;
    public const MAX_FAILED_ATTEMPTS      = 5;
    public const LOCK_MINUTES             = 15;
    public const PASSWORD_MIN             = 10;
    public const PASSWORD_MAX             = 128;

    /** Statuses that may sign in (after the password is set). */
    public const LOGIN_STATUSES = ['onboarding', 'kyc_pending', 'active'];

    private ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db;
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    // ------------------------------------------------------------------ lookup

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db()->table('engage_sellers')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return $this->db()->query('SELECT * FROM engage_sellers WHERE LOWER(email) = ? LIMIT 1', [$email])->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $row = $this->db()->query(
            "SELECT s.* FROM engage_seller_referral_codes r JOIN engage_sellers s ON s.id = r.seller_id
              WHERE UPPER(r.code) = ? AND r.status = 'active' LIMIT 1",
            [$code]
        )->getRowArray();

        return $row ?: ($this->db()->query('SELECT * FROM engage_sellers WHERE UPPER(seller_code) = ? LIMIT 1', [$code])->getRowArray() ?: null);
    }

    /** @return array<string,mixed>|null */
    public function currentAgreement(): ?array
    {
        return $this->db()->query("SELECT id, version_label, title, body, published_at FROM engage_seller_agreement_versions WHERE status = 'current' LIMIT 1")->getRowArray() ?: null;
    }

    /**
     * What the seller portal shows about the signed-in seller. Never a hash, token or KYC number.
     *
     * @param array<string,mixed> $s engage_sellers row
     * @return array<string,mixed>
     */
    public function publicView(array $s): array
    {
        $current = $this->currentAgreement();
        $acceptedVersion = null;
        if (! empty($s['agreement_version_id'])) {
            $acceptedVersion = $this->db()->table('engage_seller_agreement_versions')->select('id, version_label')
                ->where('id', (int) $s['agreement_version_id'])->get()->getRowArray();
        }
        $acceptedCurrent = $current !== null && (int) ($s['agreement_version_id'] ?? 0) === (int) $current['id'];

        return [
            'seller_code'  => $s['seller_code'],
            'name'         => $s['name'],
            'email'        => $s['email'],
            'mobile'       => $s['mobile'],
            'organization' => $s['organization'],
            'city'         => $s['city'],
            'state'        => $s['state'],
            'status'       => $s['status'],
            'kyc_status'   => $s['kyc_status'],
            'activated_at' => Clock::iso($s['activated_at'] ?? null),
            'created_at'   => Clock::iso($s['created_at'] ?? null),
            'last_login_at'=> Clock::iso($s['last_login_at'] ?? null),
            'agreement'    => [
                'accepted_version_id' => $acceptedVersion ? (int) $acceptedVersion['id'] : null,
                'accepted_version'    => $acceptedVersion['version_label'] ?? null,
                'accepted_at'         => Clock::iso($s['agreement_accepted_at'] ?? null),
                'current_version_id'  => $current ? (int) $current['id'] : null,
                'current_version'     => $current['version_label'] ?? null,
                'accepted_current'    => $acceptedCurrent,
            ],
            'next_step'          => self::nextStep($s, $current !== null, $acceptedCurrent),
            'referral_code'      => $s['seller_code'],
            'can_register_leads' => in_array($s['status'], ['kyc_pending', 'active'], true),
            'payouts_enabled'    => false,
        ];
    }

    public static function nextStep(array $s, bool $agreementPublished, bool $acceptedCurrent): ?string
    {
        if ($s['status'] === 'onboarding') {
            return $agreementPublished ? 'accept_agreement' : 'agreement_unavailable';
        }
        if ($agreementPublished && ! $acceptedCurrent && in_array($s['status'], ['kyc_pending', 'active'], true)) {
            return 'accept_agreement';
        }

        return match ($s['kyc_status']) {
            'not_submitted' => 'submit_kyc',
            'kyc_submitted' => 'kyc_under_review',
            'kyc_rejected'  => 'resubmit_kyc',
            default         => null,
        };
    }

    // ------------------------------------------------------------------ passwords

    /** Error message, or null when the password is acceptable (partner.aicountly.com rules: ≥10, a letter and a number). */
    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        $len = strlen($password);
        if ($len < self::PASSWORD_MIN) {
            return 'Password must be at least ' . self::PASSWORD_MIN . ' characters.';
        }
        if ($len > self::PASSWORD_MAX) {
            return 'Password must be at most ' . self::PASSWORD_MAX . ' characters.';
        }
        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        if ($email !== '' && strcasecmp($password, $email) === 0) {
            return 'Password must not be your email address.';
        }

        return null;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public static function isLocked(array $s, ?int $now = null): bool
    {
        $until = (string) ($s['locked_until'] ?? '');

        return $until !== '' && (int) strtotime($until) > ($now ?? time());
    }

    public function recordFailedAttempt(int $sellerId): void
    {
        $this->db()->query(
            "UPDATE engage_sellers SET
                failed_attempts = CASE WHEN failed_attempts + 1 >= ? THEN 0 ELSE failed_attempts + 1 END,
                locked_until    = CASE WHEN failed_attempts + 1 >= ? THEN NOW() + make_interval(mins => ?) ELSE locked_until END,
                updated_at = NOW()
              WHERE id = ?",
            [self::MAX_FAILED_ATTEMPTS, self::MAX_FAILED_ATTEMPTS, self::LOCK_MINUTES, $sellerId]
        );
    }

    public function setPassword(int $sellerId, string $password): void
    {
        $this->db()->table('engage_sellers')->where('id', $sellerId)->update([
            'password_hash'   => self::hashPassword($password),
            'password_set_at' => Clock::now(),
            'failed_attempts' => 0,
            'locked_until'    => null,
            'updated_at'      => Clock::now(),
        ]);
    }

    // ------------------------------------------------------------------ sessions

    /** @return array{token:string, expires_at:string, idle_timeout_seconds:int} */
    public function createSession(int $sellerId, string $ip, string $ua): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + self::SESSION_ABSOLUTE_SECONDS;
        $this->db()->table('engage_seller_sessions')->insert([
            'seller_id'  => $sellerId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => Clock::at($expires),
            'ip_address' => substr($ip, 0, 64),
            'user_agent' => substr($ua, 0, 255),
        ]);

        return ['token' => $token, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires), 'idle_timeout_seconds' => self::SESSION_IDLE_SECONDS];
    }

    /**
     * Resolve a session token. ['ok'=>true, 'seller'=>row, 'session'=>row] or
     * ['ok'=>false, 'status'=>401|403, 'code'=>..., 'error'=>...].
     *
     * @return array<string,mixed>
     */
    public function resolveSession(string $token): array
    {
        $expired = ['ok' => false, 'status' => 401, 'code' => 'session_expired', 'error' => 'Your session has ended. Please sign in again.'];
        if (! preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            return $expired;
        }
        $session = $this->db()->table('engage_seller_sessions')->where('token_hash', hash('sha256', $token))->get()->getRowArray();
        if (! $session) {
            return $expired;
        }
        if (! empty($session['revoked_at'])) {
            $reason = (string) ($session['revoked_reason'] ?? '');
            $current = in_array($reason, ['suspended', 'terminated'], true) ? $this->find((int) $session['seller_id']) : null;
            if ($current === null || $current['status'] !== $reason) {
                return $expired; // e.g. revoked at suspension, seller since reactivated: sign in again
            }
            if ($reason === 'suspended') {
                return ['ok' => false, 'status' => 403, 'code' => 'seller_suspended', 'error' => 'Your seller account is suspended. Contact AICOUNTLY for details.'];
            }
            if ($reason === 'terminated') {
                return ['ok' => false, 'status' => 403, 'code' => 'seller_terminated', 'error' => 'Your seller account has been closed.'];
            }

            return $expired;
        }
        $now = time();
        if ((int) strtotime((string) $session['expires_at']) <= $now) {
            $this->revokeSession((int) $session['id'], 'expired');

            return $expired;
        }
        if ((int) strtotime((string) $session['last_seen_at']) + self::SESSION_IDLE_SECONDS <= $now) {
            $this->revokeSession((int) $session['id'], 'idle');

            return $expired;
        }
        $seller = $this->find((int) $session['seller_id']);
        if (! $seller) {
            return $expired;
        }
        if ($seller['status'] === 'suspended') {
            $this->revokeSession((int) $session['id'], 'suspended');

            return ['ok' => false, 'status' => 403, 'code' => 'seller_suspended', 'error' => 'Your seller account is suspended. Contact AICOUNTLY for details.'];
        }
        if ($seller['status'] === 'terminated') {
            $this->revokeSession((int) $session['id'], 'terminated');

            return ['ok' => false, 'status' => 403, 'code' => 'seller_terminated', 'error' => 'Your seller account has been closed.'];
        }
        if ((int) strtotime((string) $session['last_seen_at']) < $now - 60) {
            $this->db()->table('engage_seller_sessions')->where('id', (int) $session['id'])->update(['last_seen_at' => Clock::now()]);
        }

        return ['ok' => true, 'seller' => $seller, 'session' => $session];
    }

    public function revokeSession(int $sessionId, string $reason): void
    {
        $this->db()->query('UPDATE engage_seller_sessions SET revoked_at = NOW(), revoked_reason = ? WHERE id = ? AND revoked_at IS NULL', [$reason, $sessionId]);
    }

    /** Revoke every live session of a seller (except $keepSessionId). Returns how many. */
    public function revokeAllSessions(int $sellerId, string $reason, ?int $keepSessionId = null): int
    {
        $sql = 'UPDATE engage_seller_sessions SET revoked_at = NOW(), revoked_reason = ? WHERE seller_id = ? AND revoked_at IS NULL';
        $binds = [$reason, $sellerId];
        if ($keepSessionId !== null) {
            $sql .= ' AND id <> ?';
            $binds[] = $keepSessionId;
        }
        $this->db()->query($sql, $binds);

        return $this->db()->affectedRows();
    }

    // ------------------------------------------------------------------ one-time tokens

    /**
     * Issue a one-time token (previous unused tokens of the same purpose are revoked).
     *
     * @return array{token:string, id:int, expires_at:string, code?:string}
     */
    public function issueToken(int $sellerId, string $purpose, ?int $staffId = null, string $ip = '', bool $withCode = false, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? match ($purpose) {
            'activation'     => self::ACTIVATION_SECONDS,
            'password_reset' => self::RESET_SECONDS,
            'login_otp'      => self::OTP_SECONDS,
        };
        $this->db()->query('UPDATE engage_seller_tokens SET revoked_at = NOW() WHERE seller_id = ? AND purpose = ? AND used_at IS NULL AND revoked_at IS NULL', [$sellerId, $purpose]);
        $token = bin2hex(random_bytes(32));
        $code = $withCode ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : null;
        $expires = time() + $ttl;
        $this->db()->table('engage_seller_tokens')->insert([
            'seller_id'  => $sellerId,
            'purpose'    => $purpose,
            'token_hash' => hash('sha256', $token),
            'code_hash'  => $code !== null ? password_hash($code, PASSWORD_BCRYPT) : null,
            'expires_at' => Clock::at($expires),
            'created_by' => $staffId,
            'ip_address' => $ip !== '' ? substr($ip, 0, 64) : null,
        ]);
        $out = ['token' => $token, 'id' => (int) $this->db()->insertID(), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires)];
        if ($code !== null) {
            $out['code'] = $code;
        }

        return $out;
    }

    /** A live (unused, unrevoked, unexpired) token of that purpose, or null. @return array<string,mixed>|null */
    public function liveToken(string $token, string $purpose): ?array
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            return null;
        }
        $row = $this->db()->query(
            'SELECT * FROM engage_seller_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token), $purpose]
        )->getRowArray();

        return $row ?: null;
    }

    /** Mark used; false when someone else used it first (single use, race-safe). */
    public function consumeToken(int $tokenId): bool
    {
        $this->db()->query('UPDATE engage_seller_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL', [$tokenId]);

        return $this->db()->affectedRows() === 1;
    }

    public function setTokenDelivery(int $tokenId, string $delivery): void
    {
        $this->db()->table('engage_seller_tokens')->where('id', $tokenId)->update(['delivery' => substr($delivery, 0, 32)]);
    }

    public function revokeTokens(int $sellerId, ?string $purpose = null): void
    {
        $sql = 'UPDATE engage_seller_tokens SET revoked_at = NOW() WHERE seller_id = ? AND used_at IS NULL AND revoked_at IS NULL';
        $binds = [$sellerId];
        if ($purpose !== null) {
            $sql .= ' AND purpose = ?';
            $binds[] = $purpose;
        }
        $this->db()->query($sql, $binds);
    }

    // ------------------------------------------------------------------ links

    public static function portalBase(): string
    {
        return rtrim(trim((string) env('SELLER_PORTAL_BASE_URL', '')) ?: 'https://seller.aicountly.com', '/');
    }

    public static function activationUrl(string $token): string
    {
        return self::portalBase() . '/activate?token=' . $token;
    }

    public static function resetUrl(string $token): string
    {
        return self::portalBase() . '/reset-password?token=' . $token;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = mb_substr($local, 0, 1);

        return $keep . str_repeat('•', max(2, mb_strlen($local) - 1)) . '@' . $domain;
    }

    // ------------------------------------------------------------------ status

    /**
     * Move a seller to a new status and record who/why. Revokes sessions when access ends.
     */
    public function changeStatus(int $sellerId, string $status, ?string $reason, ?int $staffId): void
    {
        $update = [
            'status'            => $status,
            'status_reason'     => $reason,
            'status_changed_at' => Clock::now(),
            'status_changed_by' => $staffId,
            'updated_at'        => Clock::now(),
        ];
        if ($status === 'terminated') {
            $update['terminated_at'] = Clock::now();
        }
        $this->db()->table('engage_sellers')->where('id', $sellerId)->update($update);
        if (in_array($status, ['suspended', 'terminated'], true)) {
            $this->revokeAllSessions($sellerId, $status);
            $this->revokeTokens($sellerId);
        }
        if ($status === 'terminated') {
            $this->db()->table('engage_seller_referral_codes')->where('seller_id', $sellerId)->update(['status' => 'disabled']);
        }
    }

    /** The status a seller returns to after onboarding steps / reactivation, from agreement + KYC. */
    public function settledStatus(array $s): string
    {
        if (empty($s['agreement_version_id'])) {
            return 'onboarding';
        }

        return ($s['kyc_status'] ?? '') === 'kyc_verified' ? 'active' : 'kyc_pending';
    }

    public function nextSellerCode(): string
    {
        $n = (int) $this->db()->query("SELECT nextval('engage_seller_code_seq') AS n")->getRow()->n;

        return 'SLR-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}

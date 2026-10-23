<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Models\SettingsModel;
use Config\Services;

/**
 * Seller sign-in flows for the seller-portal API (shapes: seller-aicountly docs/ENGAGE_SELLER_API.md § 3):
 * login (+ optional email OTP), activation, password reset, change password, logout, agreement acceptance.
 *
 * Every method returns a result array the controller relays:
 *   ['status' => 2xx, 'data' => [...]]  or  ['status' => 4xx/5xx, 'code' => '...', 'error' => '...', 'details' => ?array]
 *
 * Credential failures share one generic answer so the login form cannot reveal which emails are sellers;
 * lock / termination are disclosed only after the correct password (partner.aicountly.com pattern).
 */
class SellerAuthService
{
    public const GENERIC_ERROR = 'Invalid email address or password.';
    public const LOCKED_ERROR  = 'This account is temporarily locked after too many failed sign-in attempts. Try again in 15 minutes.';
    private const DUMMY_HASH = '$2y$10$usesomesillystringforsalt0000000000000000000000000000000';

    public function __construct(private ?SellerAccountService $accounts = null, private ?SellerMailer $mailer = null)
    {
        $this->accounts ??= Services::sellerAccounts();
        $this->mailer   ??= Services::sellerMailer();
    }

    public function otpRequired(): bool
    {
        return $this->mailer->isConfigured() && (bool) (new SettingsModel())->getSetting('seller_login_otp_enabled', false);
    }

    /** @return array<string,mixed> */
    public function login(string $email, string $password, string $ip, string $ua): array
    {
        $generic = ['status' => 401, 'code' => 'invalid_credentials', 'error' => self::GENERIC_ERROR];
        $seller = $this->accounts->findByEmail($email);
        if ($seller === null) {
            password_verify($password, self::DUMMY_HASH);

            return $generic;
        }
        $matches = ! empty($seller['password_hash']) && password_verify($password, (string) $seller['password_hash']);
        if (SellerAccountService::isLocked($seller)) {
            return $matches ? ['status' => 423, 'code' => 'account_locked', 'error' => self::LOCKED_ERROR] : $generic;
        }
        if (! $matches) {
            $this->accounts->recordFailedAttempt((int) $seller['id']);
            $this->audit('seller.login_failed', $seller, ['reason' => 'bad_password']);

            return $generic;
        }
        if ($seller['status'] === 'terminated') {
            return ['status' => 403, 'code' => 'seller_terminated', 'error' => 'Your seller account has been closed.'];
        }
        if (! in_array($seller['status'], SellerAccountService::LOGIN_STATUSES, true)) {
            return $generic;
        }
        if (SellerAccountService::needsRehash((string) $seller['password_hash'])) {
            $this->accounts->db()->table('engage_sellers')->where('id', (int) $seller['id'])
                ->update(['password_hash' => SellerAccountService::hashPassword($password)]);
        }

        if ($this->otpRequired()) {
            $challenge = $this->accounts->issueToken((int) $seller['id'], 'login_otp', null, $ip, true);
            $mail = $this->mailer->send('seller_login_otp', (string) $seller['email'], (string) $seller['name'], [
                'name' => $seller['name'], 'code' => $challenge['code'], 'expires_minutes' => (string) intdiv(SellerAccountService::OTP_SECONDS, 60),
            ], 'seller-otp-' . $challenge['id']);
            $this->accounts->setTokenDelivery($challenge['id'], $mail['sent'] ? 'email:' . $mail['channel'] : 'not_sent');
            if (! $mail['sent']) {
                $this->accounts->revokeTokens((int) $seller['id'], 'login_otp');

                return ['status' => 503, 'code' => 'otp_unavailable', 'error' => 'We could not send your sign-in code. Try again shortly.'];
            }

            return ['status' => 200, 'data' => [
                'otp_required'         => true,
                'challenge_token'      => $challenge['token'],
                'challenge_expires_at' => $challenge['expires_at'],
                'email_masked'         => SellerAccountService::maskEmail((string) $seller['email']),
            ]];
        }

        return $this->startSession($seller, $ip, $ua);
    }

    /** @return array<string,mixed> */
    public function verifyOtp(string $challengeToken, string $code, string $ip, string $ua): array
    {
        $expired = ['status' => 410, 'code' => 'challenge_expired', 'error' => 'This sign-in code has expired. Sign in again.'];
        $row = $this->accounts->liveToken($challengeToken, 'login_otp');
        if ($row === null) {
            return $expired;
        }
        if ((int) $row['attempts'] >= 5) {
            $this->accounts->revokeTokens((int) $row['seller_id'], 'login_otp');

            return $expired;
        }
        if (! preg_match('/\A\d{6}\z/', $code) || ! password_verify($code, (string) $row['code_hash'])) {
            $this->accounts->db()->query('UPDATE engage_seller_tokens SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);
            if ((int) $row['attempts'] + 1 >= 5) {
                $this->accounts->revokeTokens((int) $row['seller_id'], 'login_otp');
            }

            return ['status' => 401, 'code' => 'invalid_otp', 'error' => 'That code is not valid.'];
        }
        if (! $this->accounts->consumeToken((int) $row['id'])) {
            return $expired;
        }
        $seller = $this->accounts->find((int) $row['seller_id']);
        if (! $seller || ! in_array($seller['status'], SellerAccountService::LOGIN_STATUSES, true)) {
            return ['status' => 401, 'code' => 'invalid_credentials', 'error' => self::GENERIC_ERROR];
        }

        return $this->startSession($seller, $ip, $ua);
    }

    /** @return array<string,mixed> */
    private function startSession(array $seller, string $ip, string $ua): array
    {
        $this->accounts->db()->table('engage_sellers')->where('id', (int) $seller['id'])->update([
            'failed_attempts' => 0, 'locked_until' => null,
            'last_login_at' => Clock::now(), 'last_login_ip' => substr($ip, 0, 64),
        ]);
        $session = $this->accounts->createSession((int) $seller['id'], $ip, $ua);
        $this->audit('seller.login', $seller, []);

        return ['status' => 200, 'data' => [
            'otp_required'         => false,
            'session_token'        => $session['token'],
            'expires_at'           => $session['expires_at'],
            'idle_timeout_seconds' => $session['idle_timeout_seconds'],
            'seller'               => $this->accounts->publicView($this->accounts->find((int) $seller['id'])),
        ]];
    }

    /** Idempotent: an unknown or already-revoked token is also {revoked: true}. @return array<string,mixed> */
    public function logout(string $token): array
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            $row = $this->accounts->db()->table('engage_seller_sessions')->where('token_hash', hash('sha256', $token))->get()->getRowArray();
            if ($row && empty($row['revoked_at'])) {
                $this->accounts->revokeSession((int) $row['id'], 'logout');
                $seller = $this->accounts->find((int) $row['seller_id']);
                if ($seller) {
                    $this->audit('seller.logout', $seller, []);
                }
            }
        }

        return ['status' => 200, 'data' => ['revoked' => true]];
    }

    // ------------------------------------------------------------------ activation

    /** @return array<string,mixed>|null [token row, seller] for a live activation token */
    private function activationTarget(string $token): ?array
    {
        $row = $this->accounts->liveToken($token, 'activation');
        $seller = $row ? $this->accounts->find((int) $row['seller_id']) : null;
        if (! $seller || $seller['status'] !== 'onboarding' || ! empty($seller['password_hash'])) {
            return null;
        }

        return [$row, $seller];
    }

    private static function tokenInvalid(string $what): array
    {
        return ['status' => 410, 'code' => 'token_invalid', 'error' => "This {$what} link has expired or has already been used."];
    }

    /** POST auth/activation/inspect — does not consume the token. @return array<string,mixed> */
    public function inspectActivation(string $token): array
    {
        $t = $this->activationTarget($token);
        if ($t === null) {
            return self::tokenInvalid('activation');
        }
        [$row, $seller] = $t;

        return ['status' => 200, 'data' => [
            'email_masked' => SellerAccountService::maskEmail((string) $seller['email']),
            'name'         => $seller['name'],
            'expires_at'   => Clock::iso($row['expires_at']),
        ]];
    }

    /** POST auth/activation — set the password; status stays onboarding until the agreement is accepted. @return array<string,mixed> */
    public function activate(string $token, string $password): array
    {
        $t = $this->activationTarget($token);
        if ($t === null) {
            return self::tokenInvalid('activation');
        }
        [$row, $seller] = $t;
        $problem = SellerAccountService::passwordProblem($password, (string) $seller['email']);
        if ($problem !== null) {
            return ['status' => 422, 'code' => 'weak_password', 'error' => $problem, 'details' => ['password' => $problem]];
        }
        if (! $this->accounts->consumeToken((int) $row['id'])) {
            return self::tokenInvalid('activation');
        }
        $this->accounts->setPassword((int) $seller['id'], $password);
        $this->accounts->db()->table('engage_sellers')->where('id', (int) $seller['id'])->update(['activated_at' => Clock::now()]);
        $this->audit('seller.activated', $seller, []);

        return ['status' => 200, 'data' => ['activated' => true, 'email_masked' => SellerAccountService::maskEmail((string) $seller['email'])]];
    }

    // ------------------------------------------------------------------ agreement

    /** GET agreement. @return array<string,mixed> */
    public function agreementState(array $seller): array
    {
        $current = $this->accounts->currentAgreement();
        $acceptedLabel = null;
        if (! empty($seller['agreement_version_id'])) {
            $acceptedLabel = $this->accounts->db()->table('engage_seller_agreement_versions')->select('version_label')
                ->where('id', (int) $seller['agreement_version_id'])->get()->getRow()->version_label ?? null;
        }

        return [
            'agreement'        => $current ? self::agreementView($current) : null,
            'accepted'         => $current !== null && ! SellerAccountService::acceptanceRequired($seller, $current),
            'accepted_version' => $acceptedLabel,
            'accepted_at'      => Clock::iso($seller['agreement_accepted_at'] ?? null),
        ];
    }

    /** POST agreement/acceptances {version, accepted:true}. @return array<string,mixed> */
    public function acceptAgreement(array $seller, string $version, mixed $accepted, string $ip, string $ua): array
    {
        if ($accepted !== true) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Tick the box to accept the agreement.', 'details' => ['accepted' => 'You must accept the agreement.']];
        }
        $current = $this->accounts->currentAgreement();
        if ($current === null) {
            return ['status' => 409, 'code' => 'conflict', 'error' => 'No seller agreement has been published yet. Aicountly will contact you.'];
        }
        if ($version !== (string) $current['version_label']) {
            return ['status' => 409, 'code' => 'agreement_version_mismatch', 'error' => 'The seller agreement has changed. Reload and accept the current version.'];
        }
        $db = $this->accounts->db();
        $existing = $db->table('engage_seller_agreement_acceptances')->where('seller_id', (int) $seller['id'])
            ->where('agreement_version_id', (int) $current['id'])->get()->getRowArray();
        if ($existing) {
            $fresh = $this->accounts->find((int) $seller['id']);

            return ['status' => 200, 'data' => [
                'accepted_version' => $current['version_label'], 'accepted_at' => Clock::iso($existing['accepted_at']),
                'seller' => $this->accounts->publicView($fresh),
            ]];
        }
        $db->table('engage_seller_agreement_acceptances')->insert([
            'seller_id' => (int) $seller['id'], 'agreement_version_id' => (int) $current['id'],
            'ip_address' => substr($ip, 0, 64), 'user_agent' => substr($ua, 0, 255),
        ]);
        $db->table('engage_sellers')->where('id', (int) $seller['id'])->update([
            'agreement_version_id' => (int) $current['id'], 'agreement_accepted_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $fresh = $this->accounts->find((int) $seller['id']);
        if ($fresh['status'] === 'onboarding') {
            $this->accounts->changeStatus((int) $seller['id'], $this->accounts->settledStatus($fresh), 'Agreement accepted', null);
            $fresh = $this->accounts->find((int) $seller['id']);
        }
        $this->audit('seller.agreement_accepted', $seller, ['agreement_version' => $current['version_label']]);

        return ['status' => 201, 'data' => [
            'accepted_version' => $current['version_label'], 'accepted_at' => Clock::iso($fresh['agreement_accepted_at']),
            'seller' => $this->accounts->publicView($fresh),
        ]];
    }

    /** @param array<string,mixed> $a */
    public static function agreementView(array $a): array
    {
        return [
            'version'      => $a['version_label'],
            'title'        => $a['title'],
            'body_text'    => $a['body'],
            'published_at' => Clock::iso($a['published_at'] ?? null),
        ];
    }

    // ------------------------------------------------------------------ password reset

    /**
     * POST auth/password-reset/request — 202 {accepted:true} whether or not the account exists.
     * No mailer configured → 503 for everyone (nothing can be sent; never pretend). A seller still
     * onboarding without a password gets a fresh activation link instead.
     *
     * @return array<string,mixed>
     */
    public function requestReset(string $email, string $ip): array
    {
        $unavailable = ['status' => 503, 'code' => 'mailer_unavailable', 'error' => 'We could not send the email. Try again later or contact Aicountly.'];
        if (! $this->mailer->isConfigured()) {
            return $unavailable;
        }
        $answer = ['status' => 202, 'data' => ['accepted' => true]];
        $seller = $this->accounts->findByEmail($email);
        if (! $seller || ! in_array($seller['status'], SellerAccountService::RESET_STATUSES, true)) {
            return $answer;
        }
        $purpose = empty($seller['password_hash']) ? 'activation' : 'password_reset';
        if ($purpose === 'activation' && $seller['status'] !== 'onboarding') {
            return $answer;
        }
        $recent = (int) $this->accounts->db()->query(
            "SELECT COUNT(*) AS n FROM engage_seller_tokens WHERE seller_id = ? AND purpose = ? AND created_at > NOW() - INTERVAL '1 hour'",
            [(int) $seller['id'], $purpose]
        )->getRow()->n;
        if ($recent >= 3) {
            return $answer;
        }
        $t = $this->accounts->issueToken((int) $seller['id'], $purpose, null, $ip);
        $vars = $purpose === 'activation'
            ? ['name' => $seller['name'], 'seller_code' => $seller['seller_code'], 'activation_url' => SellerAccountService::activationUrl($t['token']), 'expires_at' => $t['expires_at']]
            : ['name' => $seller['name'], 'reset_url' => SellerAccountService::resetUrl($t['token']), 'expires_at' => $t['expires_at']];
        $mail = $this->mailer->send($purpose === 'activation' ? 'seller_activation' : 'seller_password_reset',
            (string) $seller['email'], (string) $seller['name'], $vars, "seller-{$purpose}-{$t['id']}");
        $this->accounts->setTokenDelivery($t['id'], $mail['sent'] ? 'email:' . $mail['channel'] : 'not_sent');
        $this->audit('seller.password_reset_requested', $seller, ['email_sent' => $mail['sent'], 'purpose' => $purpose]);
        if (! $mail['sent']) {
            $this->accounts->revokeTokens((int) $seller['id'], $purpose);

            return $unavailable;
        }

        return $answer;
    }

    /** @return array<string,mixed>|null */
    private function resetTarget(string $token): ?array
    {
        $row = $this->accounts->liveToken($token, 'password_reset');
        $seller = $row ? $this->accounts->find((int) $row['seller_id']) : null;
        if (! $seller || ! in_array($seller['status'], SellerAccountService::RESET_STATUSES, true)) {
            return null;
        }

        return [$row, $seller];
    }

    /** @return array<string,mixed> */
    public function inspectReset(string $token): array
    {
        $t = $this->resetTarget($token);
        if ($t === null) {
            return self::tokenInvalid('reset');
        }
        [$row, $seller] = $t;

        return ['status' => 200, 'data' => [
            'email_masked' => SellerAccountService::maskEmail((string) $seller['email']),
            'name'         => $seller['name'],
            'expires_at'   => Clock::iso($row['expires_at']),
        ]];
    }

    /** @return array<string,mixed> */
    public function resetPassword(string $token, string $password): array
    {
        $t = $this->resetTarget($token);
        if ($t === null) {
            return self::tokenInvalid('reset');
        }
        [$row, $seller] = $t;
        $problem = SellerAccountService::passwordProblem($password, (string) $seller['email']);
        if ($problem !== null) {
            return ['status' => 422, 'code' => 'weak_password', 'error' => $problem, 'details' => ['password' => $problem]];
        }
        if (! $this->accounts->consumeToken((int) $row['id'])) {
            return self::tokenInvalid('reset');
        }
        $this->accounts->setPassword((int) $seller['id'], $password);
        $this->accounts->revokeAllSessions((int) $seller['id'], 'password_reset');
        $this->audit('seller.password_reset', $seller, []);

        return ['status' => 200, 'data' => ['reset' => true]];
    }

    /** POST me/password — every session (this one too) is revoked and a new one issued. @return array<string,mixed> */
    public function changePassword(array $seller, string $current, string $new, string $ip, string $ua): array
    {
        if (empty($seller['password_hash']) || ! password_verify($current, (string) $seller['password_hash'])) {
            return ['status' => 422, 'code' => 'invalid_current_password', 'error' => 'Your current password is not correct.', 'details' => ['current_password' => 'Your current password is not correct.']];
        }
        $problem = SellerAccountService::passwordProblem($new, (string) $seller['email']);
        if ($problem !== null) {
            return ['status' => 422, 'code' => 'weak_password', 'error' => $problem, 'details' => ['new_password' => $problem]];
        }
        $this->accounts->setPassword((int) $seller['id'], $new);
        $revoked = $this->accounts->revokeAllSessions((int) $seller['id'], 'password_changed');
        $session = $this->accounts->createSession((int) $seller['id'], $ip, $ua);
        $this->audit('seller.password_changed', $seller, ['sessions_revoked' => $revoked]);

        return ['status' => 200, 'data' => ['session_token' => $session['token'], 'expires_at' => $session['expires_at']]];
    }

    /** Audit an action done by (or to) a seller, with the seller as the actor. */
    public function audit(string $event, array $seller, array $meta): void
    {
        Services::auditService()->log($event, [
            'actor_id'     => null,
            'actor_email'  => (string) ($seller['email'] ?? ''),
            'actor_role'   => 'seller',
            'subject_kind' => 'seller',
            'subject_id'   => $seller['id'] ?? null,
            'metadata'     => array_merge(['seller_code' => $seller['seller_code'] ?? null], $meta),
        ]);
    }
}

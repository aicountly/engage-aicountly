<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Models\SettingsModel;
use Config\Services;

/**
 * Seller sign-in flows for the seller-portal API: login (+ optional email OTP), activation,
 * forgot / reset password, change password, logout, agreement acceptance.
 *
 * Every method returns a result array the controller relays:
 *   ['status' => 200, 'data' => [...]]  or  ['status' => 4xx/5xx, 'code' => '...', 'error' => '...', 'details' => ?array]
 *
 * Credential failures share one generic message so the login form cannot reveal which emails are sellers;
 * suspension / lock are disclosed only after the correct password was given (partner.aicountly.com pattern).
 */
class SellerAuthService
{
    public const GENERIC_ERROR = 'Invalid email address or password.';
    public const LOCKED_ERROR  = 'This account is temporarily locked after too many failed sign-in attempts. Try again in 15 minutes.';
    public const FORGOT_MESSAGE = 'If an active seller account uses that email address, we have sent a link to reset the password.';
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
        if ($seller['status'] === 'suspended') {
            return ['status' => 403, 'code' => 'seller_suspended', 'error' => 'Your seller account is suspended. Contact AICOUNTLY for details.'];
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
                'otp_required'    => true,
                'challenge_token' => $challenge['token'],
                'expires_at'      => $challenge['expires_at'],
                'sent_to'         => SellerAccountService::maskEmail((string) $seller['email']),
            ]];
        }

        return $this->startSession($seller, $ip, $ua);
    }

    /** @return array<string,mixed> */
    public function verifyOtp(string $challengeToken, string $code, string $ip, string $ua): array
    {
        $invalid = ['status' => 401, 'code' => 'otp_invalid', 'error' => 'That code is not valid. Sign in again to get a new code.'];
        $row = $this->accounts->liveToken($challengeToken, 'login_otp');
        if ($row === null) {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This sign-in code has expired. Sign in again.'];
        }
        if ((int) $row['attempts'] >= 5) {
            $this->accounts->revokeTokens((int) $row['seller_id'], 'login_otp');

            return $invalid;
        }
        if (! preg_match('/\A\d{6}\z/', $code) || ! password_verify($code, (string) $row['code_hash'])) {
            $this->accounts->db()->query('UPDATE engage_seller_tokens SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);

            return ['status' => 401, 'code' => 'otp_invalid', 'error' => 'That code is not valid.'];
        }
        if (! $this->accounts->consumeToken((int) $row['id'])) {
            return $invalid;
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

    public function logout(int $sessionId, array $seller): array
    {
        $this->accounts->revokeSession($sessionId, 'logout');
        $this->audit('seller.logout', $seller, []);

        return ['status' => 200, 'data' => ['signed_out' => true]];
    }

    // ------------------------------------------------------------------ activation

    /** @return array<string,mixed> */
    public function checkActivation(string $token): array
    {
        $row = $this->accounts->liveToken($token, 'activation');
        $seller = $row ? $this->accounts->find((int) $row['seller_id']) : null;
        if (! $seller || $seller['status'] !== 'onboarding') {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This activation link has expired or has already been used. Ask AICOUNTLY to send a new one.'];
        }
        $agreement = $this->accounts->currentAgreement();

        return ['status' => 200, 'data' => [
            'valid'       => true,
            'name'        => $seller['name'],
            'email'       => SellerAccountService::maskEmail((string) $seller['email']),
            'seller_code' => $seller['seller_code'],
            'expires_at'  => Clock::iso($row['expires_at']),
            'agreement'   => $agreement ? self::agreementView($agreement) : null,
        ]];
    }

    /** @return array<string,mixed> */
    public function activate(string $token, string $password, mixed $agreementVersionId, string $ip, string $ua): array
    {
        $row = $this->accounts->liveToken($token, 'activation');
        $seller = $row ? $this->accounts->find((int) $row['seller_id']) : null;
        if (! $seller || $seller['status'] !== 'onboarding') {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This activation link has expired or has already been used. Ask AICOUNTLY to send a new one.'];
        }
        $problem = SellerAccountService::passwordProblem($password, (string) $seller['email']);
        if ($problem !== null) {
            return ['status' => 422, 'code' => 'weak_password', 'error' => $problem, 'details' => ['password' => $problem]];
        }
        $current = $this->accounts->currentAgreement();
        if ($agreementVersionId !== null && $agreementVersionId !== '' && ($current === null || (int) $agreementVersionId !== (int) $current['id'])) {
            return ['status' => 409, 'code' => 'agreement_version_mismatch', 'error' => 'The seller agreement has changed. Reload the page and accept the current version.'];
        }
        if (! $this->accounts->consumeToken((int) $row['id'])) {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This activation link has already been used.'];
        }
        $this->accounts->setPassword((int) $seller['id'], $password);
        $this->accounts->db()->table('engage_sellers')->where('id', (int) $seller['id'])->update(['activated_at' => Clock::now()]);
        $this->audit('seller.activated', $seller, []);

        if ($agreementVersionId !== null && $agreementVersionId !== '' && $current !== null) {
            $this->recordAcceptance($this->accounts->find((int) $seller['id']), (int) $current['id'], $ip, $ua);
        }

        $fresh = $this->accounts->find((int) $seller['id']);
        $session = $this->accounts->createSession((int) $seller['id'], $ip, $ua);

        return ['status' => 200, 'data' => [
            'activated'            => true,
            'session_token'        => $session['token'],
            'expires_at'           => $session['expires_at'],
            'idle_timeout_seconds' => $session['idle_timeout_seconds'],
            'seller'               => $this->accounts->publicView($fresh),
        ]];
    }

    // ------------------------------------------------------------------ agreement

    /** @return array<string,mixed> */
    public function acceptAgreement(array $seller, mixed $versionId, string $ip, string $ua): array
    {
        $current = $this->accounts->currentAgreement();
        if ($current === null) {
            return ['status' => 409, 'code' => 'conflict', 'error' => 'No seller agreement has been published yet. AICOUNTLY will contact you.'];
        }
        if ((int) $versionId !== (int) $current['id']) {
            return ['status' => 409, 'code' => 'agreement_version_mismatch', 'error' => 'The seller agreement has changed. Reload and accept the current version.'];
        }
        $this->recordAcceptance($seller, (int) $current['id'], $ip, $ua);

        return ['status' => 200, 'data' => ['accepted' => true, 'seller' => $this->accounts->publicView($this->accounts->find((int) $seller['id']))]];
    }

    private function recordAcceptance(array $seller, int $versionId, string $ip, string $ua): void
    {
        $db = $this->accounts->db();
        $db->query('INSERT INTO engage_seller_agreement_acceptances (seller_id, agreement_version_id, ip_address, user_agent)
                    VALUES (?, ?, ?, ?) ON CONFLICT (seller_id, agreement_version_id) DO NOTHING',
            [(int) $seller['id'], $versionId, substr($ip, 0, 64), substr($ua, 0, 255)]);
        $db->table('engage_sellers')->where('id', (int) $seller['id'])->update([
            'agreement_version_id' => $versionId, 'agreement_accepted_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $fresh = $this->accounts->find((int) $seller['id']);
        if ($fresh['status'] === 'onboarding') {
            $this->accounts->changeStatus((int) $seller['id'], $this->accounts->settledStatus($fresh), 'Agreement accepted', null);
        }
        $this->audit('seller.agreement_accepted', $seller, ['agreement_version_id' => $versionId]);
    }

    /** @param array<string,mixed> $a */
    public static function agreementView(array $a): array
    {
        return [
            'id'           => (int) $a['id'],
            'version'      => $a['version_label'],
            'title'        => $a['title'],
            'body'         => $a['body'],
            'published_at' => Clock::iso($a['published_at'] ?? null),
        ];
    }

    // ------------------------------------------------------------------ password reset

    /** Always the same answer, whether or not the email belongs to a seller. @return array<string,mixed> */
    public function forgotPassword(string $email, string $ip): array
    {
        $answer = ['status' => 200, 'data' => ['message' => self::FORGOT_MESSAGE]];
        $seller = $this->accounts->findByEmail($email);
        if (! $seller || empty($seller['password_hash']) || ! in_array($seller['status'], SellerAccountService::LOGIN_STATUSES, true)) {
            return $answer;
        }
        $recent = (int) $this->accounts->db()->query(
            "SELECT COUNT(*) AS n FROM engage_seller_tokens WHERE seller_id = ? AND purpose = 'password_reset' AND created_at > NOW() - INTERVAL '1 hour'",
            [(int) $seller['id']]
        )->getRow()->n;
        if ($recent >= 3) {
            return $answer;
        }
        $t = $this->accounts->issueToken((int) $seller['id'], 'password_reset', null, $ip);
        $mail = $this->mailer->send('seller_password_reset', (string) $seller['email'], (string) $seller['name'], [
            'name' => $seller['name'], 'reset_url' => SellerAccountService::resetUrl($t['token']), 'expires_at' => $t['expires_at'],
        ], 'seller-reset-' . $t['id']);
        $this->accounts->setTokenDelivery($t['id'], $mail['sent'] ? 'email:' . $mail['channel'] : 'not_sent');
        if (! $mail['sent']) {
            // Nobody can receive this link: do not leave a live token behind.
            $this->accounts->revokeTokens((int) $seller['id'], 'password_reset');
            log_message('warning', 'Seller password reset requested for ' . $seller['seller_code'] . ' but no email could be sent (' . ($mail['reason'] ?? '') . ').');
        }
        $this->audit('seller.password_reset_requested', $seller, ['email_sent' => $mail['sent']]);

        return $answer;
    }

    /** @return array<string,mixed> */
    public function checkReset(string $token): array
    {
        $row = $this->accounts->liveToken($token, 'password_reset');
        $seller = $row ? $this->accounts->find((int) $row['seller_id']) : null;
        if (! $seller || ! in_array($seller['status'], SellerAccountService::LOGIN_STATUSES, true)) {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This reset link has expired or has already been used.'];
        }

        return ['status' => 200, 'data' => ['valid' => true, 'email' => SellerAccountService::maskEmail((string) $seller['email']), 'expires_at' => Clock::iso($row['expires_at'])]];
    }

    /** @return array<string,mixed> */
    public function resetPassword(string $token, string $password): array
    {
        $row = $this->accounts->liveToken($token, 'password_reset');
        $seller = $row ? $this->accounts->find((int) $row['seller_id']) : null;
        if (! $seller || ! in_array($seller['status'], SellerAccountService::LOGIN_STATUSES, true)) {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This reset link has expired or has already been used.'];
        }
        $problem = SellerAccountService::passwordProblem($password, (string) $seller['email']);
        if ($problem !== null) {
            return ['status' => 422, 'code' => 'weak_password', 'error' => $problem, 'details' => ['password' => $problem]];
        }
        if (! $this->accounts->consumeToken((int) $row['id'])) {
            return ['status' => 410, 'code' => 'token_invalid', 'error' => 'This reset link has already been used.'];
        }
        $this->accounts->setPassword((int) $seller['id'], $password);
        if (empty($seller['activated_at'])) {
            $this->accounts->db()->table('engage_sellers')->where('id', (int) $seller['id'])->update(['activated_at' => Clock::now()]);
        }
        $this->accounts->revokeAllSessions((int) $seller['id'], 'password_reset');
        $this->audit('seller.password_reset', $seller, []);

        return ['status' => 200, 'data' => ['message' => 'Your password has been changed. Sign in with the new password.']];
    }

    /** @return array<string,mixed> */
    public function changePassword(array $seller, int $sessionId, string $current, string $new): array
    {
        if (empty($seller['password_hash']) || ! password_verify($current, (string) $seller['password_hash'])) {
            return ['status' => 422, 'code' => 'invalid_current_password', 'error' => 'Your current password is not correct.', 'details' => ['current_password' => 'Your current password is not correct.']];
        }
        $problem = SellerAccountService::passwordProblem($new, (string) $seller['email']);
        if ($problem !== null) {
            return ['status' => 422, 'code' => 'weak_password', 'error' => $problem, 'details' => ['new_password' => $problem]];
        }
        $this->accounts->setPassword((int) $seller['id'], $new);
        $revoked = $this->accounts->revokeAllSessions((int) $seller['id'], 'password_changed', $sessionId);
        $this->audit('seller.password_changed', $seller, ['other_sessions_revoked' => $revoked]);

        return ['status' => 200, 'data' => ['changed' => true, 'other_sessions_revoked' => $revoked]];
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

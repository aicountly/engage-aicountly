<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller sign-in (no session unless noted):
 *   POST auth/login            {email, password} → {otp_required:false, session_token, expires_at, idle_timeout_seconds, seller}
 *                                                  | {otp_required:true, challenge_token, expires_at, sent_to}
 *   POST auth/login/otp        {challenge_token, code}
 *   GET  auth/activation       ?token=  → {valid, name, email (masked), seller_code, expires_at, agreement|null}
 *   POST auth/activate         {token, password, agreement_version_id?} → session (as login)
 *   POST auth/forgot-password  {email} → always the same message
 *   GET  auth/reset-password   ?token=  → {valid, email (masked), expires_at}
 *   POST auth/reset-password   {token, password}
 *   POST auth/logout           (session)
 *   POST auth/change-password  (session) {current_password, new_password}
 */
class AuthController extends BaseSellerPortalController
{
    public function login()
    {
        $in = $this->input();
        $email = trim((string) ($in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }
        if ($errors !== []) {
            return $this->failCode(422, 'validation_failed', 'Please check the details you entered.', $errors);
        }
        if ($limited = $this->throttle('login:' . $this->clientIp(), 10, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->login($email, $password, $this->clientIp(), $this->clientUa()));
    }

    public function otp()
    {
        $in = $this->input();
        if ($limited = $this->throttle('otp:' . $this->clientIp(), 10, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->verifyOtp((string) ($in['challenge_token'] ?? ''), trim((string) ($in['code'] ?? '')), $this->clientIp(), $this->clientUa()));
    }

    public function activation()
    {
        if ($limited = $this->throttle('token-check:' . $this->clientIp(), 30, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->checkActivation((string) $this->request->getGet('token')));
    }

    public function activate()
    {
        $in = $this->input();
        if ($limited = $this->throttle('activate:' . $this->clientIp(), 10, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->activate(
            (string) ($in['token'] ?? ''), (string) ($in['password'] ?? ''), $in['agreement_version_id'] ?? null, $this->clientIp(), $this->clientUa()
        ));
    }

    public function forgot()
    {
        $in = $this->input();
        $email = trim((string) ($in['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failCode(422, 'validation_failed', 'Enter a valid email address.', ['email' => 'Enter a valid email address.']);
        }
        if ($limited = $this->throttle('forgot:' . $this->clientIp(), 5, HOUR)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->forgotPassword($email, $this->clientIp()));
    }

    public function resetCheck()
    {
        if ($limited = $this->throttle('token-check:' . $this->clientIp(), 30, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->checkReset((string) $this->request->getGet('token')));
    }

    public function reset()
    {
        $in = $this->input();
        if ($limited = $this->throttle('reset:' . $this->clientIp(), 10, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->resetPassword((string) ($in['token'] ?? ''), (string) ($in['password'] ?? '')));
    }

    public function logout()
    {
        return $this->result(Services::sellerAuth()->logout($this->sessionId(), $this->seller()));
    }

    public function changePassword()
    {
        $in = $this->input();

        return $this->result(Services::sellerAuth()->changePassword($this->seller(), $this->sessionId(),
            (string) ($in['current_password'] ?? ''), (string) ($in['new_password'] ?? '')));
    }
}

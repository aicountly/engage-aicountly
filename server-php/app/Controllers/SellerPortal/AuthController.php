<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller sign-in (portal key only, except logout which reads X-Seller-Session itself):
 *   POST auth/login                    {email, password}
 *   POST auth/login/otp                {challenge_token, code}
 *   POST auth/logout                   → {revoked: true} (idempotent)
 *   POST auth/activation/inspect       {token} → {email_masked, name, expires_at}   (does not consume)
 *   POST auth/activation               {token, password} → {activated: true, email_masked}
 *   POST auth/password-reset/request   {email} → 202 {accepted: true}   (503 mailer_unavailable when no mailer)
 *   POST auth/password-reset/inspect   {token}
 *   POST auth/password-reset           {token, password} → {reset: true}
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
        if ($limited = $this->throttle('login:' . $this->clientIp(), 20, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->login($email, $password, $this->clientIp(), $this->clientUa()));
    }

    public function otp()
    {
        $in = $this->input();
        if ($limited = $this->throttle('otp:' . $this->clientIp(), 20, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->verifyOtp((string) ($in['challenge_token'] ?? ''), trim((string) ($in['code'] ?? '')), $this->clientIp(), $this->clientUa()));
    }

    public function logout()
    {
        return $this->result(Services::sellerAuth()->logout(trim((string) $this->request->getHeaderLine('X-Seller-Session'))));
    }

    public function activationInspect()
    {
        if ($limited = $this->throttle('token:' . $this->clientIp(), 30, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->inspectActivation((string) ($this->input()['token'] ?? '')));
    }

    public function activate()
    {
        $in = $this->input();
        if ($limited = $this->throttle('token:' . $this->clientIp(), 30, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->activate((string) ($in['token'] ?? ''), (string) ($in['password'] ?? '')));
    }

    public function resetRequest()
    {
        $email = trim((string) ($this->input()['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failCode(422, 'validation_failed', 'Enter a valid email address.', ['email' => 'Enter a valid email address.']);
        }
        if ($limited = $this->throttle('reset-request:' . $this->clientIp(), 10, HOUR)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->requestReset($email, $this->clientIp()));
    }

    public function resetInspect()
    {
        if ($limited = $this->throttle('token:' . $this->clientIp(), 30, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->inspectReset((string) ($this->input()['token'] ?? '')));
    }

    public function reset()
    {
        $in = $this->input();
        if ($limited = $this->throttle('token:' . $this->clientIp(), 30, MINUTE)) {
            return $limited;
        }

        return $this->result(Services::sellerAuth()->resetPassword((string) ($in['token'] ?? ''), (string) ($in['password'] ?? '')));
    }
}

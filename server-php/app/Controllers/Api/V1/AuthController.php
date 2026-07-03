<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\UsersModel;
use Config\Services;
use RuntimeException;
use Throwable;

class AuthController extends BaseApiController
{
    public function login()
    {
        try {
            $jwtSecret = (string) env('ENGAGE_JWT_SECRET', '');
            if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
                return $this->fail(
                    'Server misconfigured: set ENGAGE_JWT_SECRET (32+ chars) in api/.env',
                    503
                );
            }

            $body  = $this->input();
            $email = trim((string) ($body['email'] ?? ''));
            $pass  = (string) ($body['password'] ?? '');

            if ($email === '' || $pass === '') {
                return $this->fail('email and password required.', 400);
            }

            $users = new UsersModel();
            $user  = $users->findByEmail($email);

            if (! $user || ($user['status'] ?? 'active') !== 'active') {
                return $this->fail('Invalid credentials.', 401);
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash === '' || ! password_verify($pass, $hash)) {
                if ($hash !== '') {
                    $users->update($user['id'], ['failed_attempts' => ((int) ($user['failed_attempts'] ?? 0)) + 1]);
                }
                return $this->fail('Invalid credentials.', 401);
            }

            $roles = $users->roleCodes((int) $user['id']);
            try {
                $token = Services::jwt()->issue((int) $user['id'], $user['email'], $roles);
            } catch (RuntimeException $e) {
                return $this->fail($e->getMessage(), 503);
            }

            $users->update($user['id'], [
                'last_login_at'   => date('Y-m-d H:i:s'),
                'last_login_ip'   => $this->request->getIPAddress(),
                'failed_attempts' => 0,
            ]);

            $this->audit('login', [
                'actor_id'    => (int) $user['id'],
                'actor_email' => $user['email'],
                'actor_role'  => $roles[0] ?? null,
                'metadata'    => ['roles' => $roles],
            ]);

            return $this->ok([
                'token'   => $token,
                'expires' => (int) env('ENGAGE_JWT_TTL_MINUTES', 720) * 60,
                'user'    => [
                    'id'    => (int) $user['id'],
                    'email' => $user['email'],
                    'name'  => $user['name'],
                    'roles' => $roles,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Login failed: ' . $e->getMessage());

            return $this->fail('Login failed. Check api/writable/logs on the server.', 500);
        }
    }

    public function refresh()
    {
        $body  = $this->input();
        $token = (string) ($body['token'] ?? '');
        $payload = Services::jwt()->decode($token);
        if (! $payload) {
            return $this->fail('Invalid token.', 401);
        }
        $new = Services::jwt()->issue(
            (int) $payload['sub'],
            (string) ($payload['email'] ?? ''),
            (array) ($payload['roles'] ?? [])
        );
        return $this->ok(['token' => $new]);
    }

    public function logout()
    {
        $this->audit('logout');
        return $this->ok(['message' => 'Logged out.']);
    }

    public function me()
    {
        $u = $this->user();
        if (! $u) {
            return $this->fail('Not authenticated.', 401);
        }
        $users = new UsersModel();
        $row   = $users->find($u['id']);
        if (! $row) {
            return $this->fail('User not found.', 404);
        }
        return $this->ok([
            'id'    => (int) $row['id'],
            'email' => $row['email'],
            'name'  => $row['name'],
            'roles' => $users->roleCodes((int) $row['id']),
        ]);
    }
}

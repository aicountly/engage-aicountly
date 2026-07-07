<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\UsersModel;
use App\Services\ConsoleIdentityService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Throwable;

class AuthController extends BaseApiController
{
    private const ENGAGE_TOKEN_STORAGE_KEY = 'engage.access_token';

    public function login()
    {
        return $this->fail(
            'Local login is disabled. Sign in at console.aicountly.org and open Engage from Top Controller Apps.',
            403,
        );
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

    /**
     * GET /v1/auth/sso-callback?token= — browser redirect from Console (no SPA JS required).
     */
    public function ssoCallback()
    {
        try {
            if ($fail = $this->ensureJwtConfigured()) {
                return $this->ssoCallbackHtml('Engage Portal is not configured for Console SSO yet.', 503);
            }

            $token = trim((string) ($this->request->getGet('token') ?? ''));
            if ($token === '') {
                return $this->ssoCallbackHtml('Missing SSO token. Open Engage again from Console Top Controller Apps.', 400);
            }

            $identity = Services::consoleIdentity()->exchangeLaunchToken($token);
            if ($identity === null) {
                return $this->ssoCallbackHtml(
                    'This sign-in link expired. Go back to Console and click Engage again.',
                    401,
                );
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'controller_sso_callback');
            if ($session instanceof ResponseInterface) {
                $message = 'You do not have access to the Engage controller app.';
                if (method_exists($session, 'getJSON')) {
                    $json = $session->getJSON(true);
                    if (is_array($json) && ! empty($json['error'])) {
                        $message = (string) $json['error'];
                    }
                }

                return $this->ssoCallbackHtml($message, 403);
            }

            return $this->completeSsoInBrowser((string) $session['token']);
        } catch (Throwable $e) {
            log_message('error', 'SSO callback failed: ' . $e->getMessage());

            return $this->ssoCallbackHtml('Console SSO sign-in failed. Try again from Console.', 500);
        }
    }

    /**
     * Exchange a Console controller SSO launch token for an Engage session.
     */
    public function controllerSso()
    {
        try {
            if ($fail = $this->ensureJwtConfigured()) {
                return $fail;
            }

            $body  = $this->input();
            $token = trim((string) ($body['token'] ?? ''));
            if ($token === '') {
                return $this->fail('token required.', 400);
            }

            $identity = Services::consoleIdentity()->exchangeLaunchToken($token);
            if ($identity === null) {
                return $this->fail('Invalid or expired Console SSO token.', 401);
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'controller_sso_login');
            if ($session instanceof ResponseInterface) {
                return $session;
            }

            return $this->ok($session);
        } catch (Throwable $e) {
            log_message('error', 'Controller SSO failed: ' . $e->getMessage());

            return $this->fail('Controller SSO login failed.', 500);
        }
    }

    /**
     * Sign in using the shared Console cookie (direct visit to engage.aicountly.org).
     */
    public function consoleSession()
    {
        try {
            if ($fail = $this->ensureJwtConfigured()) {
                return $fail;
            }

            $consoleToken = trim((string) ($this->request->getCookie(ConsoleIdentityService::cookieName()) ?? ''));
            if ($consoleToken === '') {
                return $this->fail('Sign in to Console first.', 401);
            }

            $identity = Services::consoleIdentity()->introspectSession($consoleToken);
            if ($identity === null) {
                return $this->fail('Console session is invalid or expired. Sign in again at Console.', 401);
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'console_session_login');
            if ($session instanceof ResponseInterface) {
                return $session;
            }

            return $this->ok($session);
        } catch (Throwable $e) {
            log_message('error', 'Console session login failed: ' . $e->getMessage());

            return $this->fail('Console session login failed.', 500);
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>|ResponseInterface
     */
    private function buildSessionFromConsoleIdentity(array $identity, string $auditEvent): array|ResponseInterface
    {
        $active = (bool) ($identity['active'] ?? false);
        $global = (bool) ($identity['global_superadmin'] ?? false);
        if (! $active && ! $global) {
            return $this->fail('You do not have access to the Engage controller app.', 403);
        }

        $consoleUser = is_array($identity['user'] ?? null) ? $identity['user'] : [];
        $email = strtolower(trim((string) ($consoleUser['email'] ?? '')));
        $name  = trim((string) ($consoleUser['name'] ?? ''));
        if ($email === '') {
            return $this->fail('Console identity did not return a user email.', 502);
        }

        $users = new UsersModel();
        $user  = $users->findByEmail($email);

        if (! $user) {
            $userId = $users->insert([
                'email'         => $email,
                'name'          => $name !== '' ? $name : $email,
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'status'        => 'active',
            ]);

            if (! $userId) {
                return $this->fail('Could not provision Engage user from Console identity.', 500);
            }

            $this->assignRoleByCode($users, (int) $userId, 'super_admin');
            $user = $users->find((int) $userId);
        } elseif (($user['status'] ?? 'active') !== 'active') {
            return $this->fail('Engage user account is inactive.', 403);
        }

        $roles = $users->roleCodes((int) $user['id']);
        if ($roles === []) {
            $this->assignRoleByCode($users, (int) $user['id'], 'super_admin');
            $roles = $users->roleCodes((int) $user['id']);
        }

        try {
            $engageToken = Services::jwt()->issue((int) $user['id'], $user['email'], $roles);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }

        $users->update($user['id'], [
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $this->request->getIPAddress(),
            'failed_attempts' => 0,
        ]);

        $this->audit($auditEvent, [
            'actor_id'    => (int) $user['id'],
            'actor_email' => $user['email'],
            'actor_role'  => $roles[0] ?? null,
            'metadata'    => [
                'console_user_id'   => (int) ($consoleUser['id'] ?? 0),
                'global_superadmin' => $global,
            ],
        ]);

        return [
            'token'   => $engageToken,
            'expires' => (int) env('ENGAGE_JWT_TTL_MINUTES', 720) * 60,
            'user'    => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'name'  => $user['name'],
                'roles' => $roles,
            ],
        ];
    }

    private function completeSsoInBrowser(string $engageToken): ResponseInterface
    {
        $tokenJson = json_encode($engageToken, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $storageKey = json_encode(self::ENGAGE_TOKEN_STORAGE_KEY, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Signing in to Engage Portal…</title>
  <style>
    body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; color: #334155; }
  </style>
</head>
<body>
  <p>Signing you in to Engage Portal…</p>
  <script>
    try {
      localStorage.setItem({$storageKey}, {$tokenJson});
    } catch (e) {}
    location.replace('/');
  </script>
</body>
</html>
HTML;

        return $this->response
            ->setStatusCode(200)
            ->setContentType('text/html')
            ->setBody($html);
    }

    private function ssoCallbackHtml(string $message, int $status = 400): ResponseInterface
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $consoleUrl  = 'https://console.aicountly.org';
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Engage sign-in failed</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 420px; margin: 48px auto; padding: 0 16px; color: #334155; }
    .box { border: 1px solid #fecaca; background: #fef2f2; border-radius: 12px; padding: 16px; }
    a { color: #047857; }
  </style>
</head>
<body>
  <div class="box">
    <h1 style="font-size:18px;margin:0 0 8px;">Engage sign-in failed</h1>
    <p style="margin:0 0 12px;">{$safeMessage}</p>
    <p style="margin:0;"><a href="{$consoleUrl}">Return to Console</a></p>
  </div>
</body>
</html>
HTML;

        return $this->response
            ->setStatusCode($status)
            ->setContentType('text/html')
            ->setBody($html);
    }

    private function ensureJwtConfigured(): ?ResponseInterface
    {
        $jwtSecret = (string) env('ENGAGE_JWT_SECRET', '');
        if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
            return $this->fail(
                'Server misconfigured: set ENGAGE_JWT_SECRET (32+ chars) in api/.env',
                503
            );
        }

        return null;
    }

    private function assignRoleByCode(UsersModel $users, int $userId, string $roleCode): void
    {
        $db = $users->db;
        $role = $db->table('engage_roles')->where('code', $roleCode)->get()->getRow();
        if (! $role) {
            return;
        }

        $exists = $db->table('engage_user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $role->id)
            ->countAllResults() > 0;

        if ($exists) {
            return;
        }

        $db->table('engage_user_roles')->insert([
            'user_id'    => $userId,
            'role_id'    => $role->id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

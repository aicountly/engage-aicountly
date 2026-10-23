<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\ConsoleRoleSync;
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

    /**
     * POST /v1/auth/refresh {token} — re-issue a token after re-checking the
     * caller's roles with Console (see StaffAccessService::refresh).
     */
    public function refresh()
    {
        $body  = $this->input();
        $token = (string) ($body['token'] ?? '');
        try {
            $payload = Services::jwt()->decode($token);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }
        if (! $payload) {
            return $this->fail('Invalid token.', 401);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        $users  = new UsersModel();
        $user   = $userId > 0 ? $users->find($userId) : null;
        if (! $user) {
            return $this->fail('Invalid token subject.', 401);
        }
        if (! empty($user['tokens_revoked_at'])
            && (int) ($payload['iat'] ?? 0) <= (int) strtotime((string) $user['tokens_revoked_at'])) {
            return $this->fail('Session is no longer active. Sign in again from Console.', 401);
        }

        $result = Services::staffAccess()->refresh($user);
        if (! $result['ok']) {
            if ((int) $result['status'] === 403) {
                $this->audit('console_access_revoked', [
                    'actor_id'    => $userId,
                    'actor_email' => (string) $user['email'],
                    'metadata'    => ['reason' => $result['error']],
                ]);
            }

            return $this->fail((string) $result['error'], (int) $result['status']);
        }

        $roles = $result['roles'];
        if ($roles === []) {
            return $this->fail(ConsoleRoleSync::DENY_MESSAGE, 403);
        }

        $new = Services::jwt()->issue($userId, (string) $user['email'], $roles);

        return $this->ok([
            'token'   => $new,
            'expires' => (int) env('ENGAGE_JWT_TTL_MINUTES', 720) * 60,
            'roles'   => $roles,
        ]);
    }

    /**
     * Sign out: Engage tokens are stateless JWTs (12 h), so the user's tokens issued up to now are revoked
     * (engage_users.tokens_revoked_at, checked by JwtFilter and refresh) — a copied token no longer works after logout.
     */
    public function logout()
    {
        $u = $this->user();
        if ($u && (int) ($u['id'] ?? 0) > 0) {
            try {
                (new UsersModel())->update((int) $u['id'], ['tokens_revoked_at' => date('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
                log_message('error', 'Logout revocation failed: ' . $e->getMessage());
            }
        }
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
            'id'              => (int) $row['id'],
            'email'           => $row['email'],
            'name'            => $row['name'],
            'roles'           => $users->roleCodes((int) $row['id']),
            'roles_synced_at' => $row['roles_synced_at'] ?? null,
            'roles_managed_by'=> 'console',
            'controller_apps' => $this->controllerAppsForRequest(),
        ]);
    }

    public function controllerAppsLauncher()
    {
        $u = $this->user();
        if (! $u) {
            return $this->fail('Not authenticated.', 401);
        }

        $apps = $this->controllerAppsForRequest();
        if ($apps === []) {
            return $this->fail(
                'Console session required for Top Controller Apps. Sign in at console.aicountly.org first.',
                401,
            );
        }

        return $this->ok(['apps' => $apps]);
    }

    public function ssoLaunchUrl()
    {
        $u = $this->user();
        if (! $u) {
            return $this->fail('Not authenticated.', 401);
        }

        $appCode = strtolower(trim((string) ($this->request->getGet('app_code') ?? '')));
        if ($appCode === '') {
            return $this->fail('app_code query parameter is required.', 422);
        }

        $consoleToken = $this->consoleTokenFromRequest();
        if ($consoleToken === '') {
            return $this->fail('Console session required to launch controller apps.', 401);
        }

        $data = Services::consoleIdentity()->getSsoLaunchUrl($consoleToken, $appCode);
        $redirectUrl = trim((string) ($data['redirect_url'] ?? ''));
        if ($redirectUrl === '') {
            return $this->fail('Console did not return a launch URL for this app.', 502);
        }

        return $this->ok(['redirect_url' => $redirectUrl]);
    }

    /**
     * GET /v1/auth/sso-callback?token= — browser redirect from Console (no SPA JS required).
     */
    public function ssoCallback()
    {
        try {
            $jwtSecret = (string) env('ENGAGE_JWT_SECRET', '');
            if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
                return $this->ssoCallbackHtml(
                    'Server misconfigured: set ENGAGE_JWT_SECRET (32+ chars) in api/.env',
                    503,
                );
            }

            $token = trim((string) ($this->request->getGet('token') ?? $this->request->getGet('sesskey') ?? ''));
            if ($token === '') {
                return $this->ssoCallbackHtml('Missing SSO token. Open Engage again from Console Top Controller Apps.', 400);
            }

            $identity = Services::consoleIdentity()->exchangeLaunchToken($token);
            if ($identity === null) {
                return $this->ssoCallbackHtml(
                    'This sign-in link expired or Console SSO is not configured. Add CONSOLE_API_URL and CONTROLLER_APP_CODE=engage to api/.env, then try again from Console.',
                    401,
                );
            }

            $session = $this->buildSessionFromConsoleIdentity($identity, 'controller_sso_callback');
            if ($session instanceof ResponseInterface) {
                $message = 'You do not have access to the Engage controller app, or your Engage account could not be provisioned.';
                $decoded = json_decode((string) $session->getBody(), true);
                if (is_array($decoded) && is_string($decoded['error'] ?? null) && $decoded['error'] !== '') {
                    $message = $decoded['error'];
                }

                return $this->ssoCallbackHtml($message, $session->getStatusCode() >= 400 ? $session->getStatusCode() : 403);
            }

            return $this->completeSsoInBrowser((string) $session['token']);
        } catch (Throwable $e) {
            log_message('error', 'SSO callback failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());

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

        // Roles come from Console. Decide before provisioning anything so a
        // staff member without an Engage role never gets a local row.
        $decision = ConsoleRoleSync::fromIdentity($identity);
        if ($decision['status'] === 'deny') {
            $this->audit('console_access_denied', [
                'actor_email' => $email,
                'metadata'    => ['console_user_id' => (int) ($consoleUser['id'] ?? 0), 'via' => $auditEvent],
            ]);

            return $this->fail((string) $decision['message'], 403);
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

            $user = $users->find((int) $userId);
        } elseif (($user['status'] ?? 'active') !== 'active') {
            return $this->fail('Engage user account is inactive.', 403);
        }

        $synced = Services::staffAccess()->applyLoginDecision($user, $decision);
        if (! $synced['ok']) {
            return $this->fail((string) $synced['error'], (int) $synced['status']);
        }
        $roles = $synced['roles'];
        if ($roles === []) {
            return $this->fail(ConsoleRoleSync::DENY_MESSAGE, 403);
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
                'console_user_id'   => (int) ($decision['console_user_id'] ?? ($consoleUser['id'] ?? 0)),
                'global_superadmin' => $global,
                'roles'             => $roles,
                'roles_source'      => $decision['status'] === 'grant' ? 'console' : 'local_legacy',
                'authz_version'     => $decision['authz_version'] ?? null,
            ],
        ]);

        return [
            'token'   => $engageToken,
            'expires' => (int) env('ENGAGE_JWT_TTL_MINUTES', 720) * 60,
            'user'    => [
                'id'              => (int) $user['id'],
                'email'           => $user['email'],
                'name'            => $user['name'],
                'roles'           => $roles,
                'controller_apps' => $this->normalizeLauncherApps(
                    is_array($identity['controller_apps'] ?? null) ? $identity['controller_apps'] : [],
                ),
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function controllerAppsForRequest(): array
    {
        $consoleToken = $this->consoleTokenFromRequest();
        if ($consoleToken === '') {
            return [];
        }

        $data = Services::consoleIdentity()->getLauncherApps($consoleToken);
        if (! is_array($data)) {
            return [];
        }

        return $this->normalizeLauncherApps(
            is_array($data['apps'] ?? null) ? $data['apps'] : [],
        );
    }

    private function consoleTokenFromRequest(): string
    {
        return trim((string) ($this->request->getCookie(ConsoleIdentityService::cookieName()) ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $apps
     * @return list<array<string,mixed>>
     */
    private function normalizeLauncherApps(array $apps): array
    {
        $current = strtolower(trim((string) env('CONTROLLER_APP_CODE', 'engage')));

        return array_values(array_map(static function (array $app) use ($current): array {
            $code = strtolower(trim((string) ($app['code'] ?? '')));
            $app['is_current'] = $code === $current;

            return $app;
        }, $apps));
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
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
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
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
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
}

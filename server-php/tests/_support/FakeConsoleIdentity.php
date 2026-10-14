<?php

namespace Tests\Support;

use App\Services\ConsoleIdentityService;

/**
 * Stand-in for ConsoleIdentityService: no HTTP. Tests set the payloads the
 * SSO exchange / introspect and the user-access pull should return.
 */
class FakeConsoleIdentity extends ConsoleIdentityService
{
    /** @var array<string,mixed>|null */
    public ?array $identity = null;

    public bool $pullConfigured = true;

    /** @var array{status: string, data?: array<string,mixed>} */
    public array $pullResult = ['status' => 'unreachable'];

    /** @var list<array{console_user_id: ?int, email: string}> */
    public array $pullCalls = [];

    public function exchangeLaunchToken(string $token): ?array
    {
        return $this->identity;
    }

    public function introspectSession(string $consoleToken): ?array
    {
        return $this->identity;
    }

    public function getLauncherApps(string $consoleToken): ?array
    {
        return null;
    }

    public function userAccessConfigured(): bool
    {
        return $this->pullConfigured;
    }

    public function fetchUserAccess(?int $consoleUserId, string $email): array
    {
        if (! $this->pullConfigured) {
            return ['status' => 'unconfigured'];
        }
        $this->pullCalls[] = ['console_user_id' => $consoleUserId, 'email' => $email];

        return $this->pullResult;
    }
}

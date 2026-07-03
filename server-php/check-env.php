<?php

/**
 * Print ENGAGE_DB_* from .env (masked) and test PostgreSQL PDO.
 * Usage: php check-env.php
 */

declare(strict_types=1);

$root = __DIR__;
chdir($root);

echo "=== AICOUNTLY Engage API environment check ===\n";
echo 'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
echo 'pdo_pgsql: ' . (extension_loaded('pdo_pgsql') ? 'yes' : 'NO — enable in cPanel MultiPHP') . "\n";
echo '.env file: ' . (is_file($root . '/.env') ? 'yes (' . filesize($root . '/.env') . ' bytes)' : 'MISSING') . "\n";

if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/ missing — run composer install\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

if (class_exists(\CodeIgniter\Config\DotEnv::class) && is_file($root . '/.env')) {
    (new \CodeIgniter\Config\DotEnv($root))->load();
}

$read = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : (string) $value;
};

$vars = [
    'ENGAGE_DB_HOST'     => $read('ENGAGE_DB_HOST'),
    'ENGAGE_DB_PORT'     => $read('ENGAGE_DB_PORT', '5432'),
    'ENGAGE_DB_NAME'     => $read('ENGAGE_DB_NAME'),
    'ENGAGE_DB_USER'     => $read('ENGAGE_DB_USER'),
    'ENGAGE_DB_PASSWORD' => $read('ENGAGE_DB_PASSWORD'),
];

$jwtSecret = $read('ENGAGE_JWT_SECRET');
$svcKey    = $read('ENGAGE_SERVICE_KEY');
$consoleKey= $read('CONSOLE_INBOUND_KEY');
$reachKey  = $read('REACH_INBOUND_TOKEN');

echo "\n--- Auth secrets (from .env) ---\n";
echo 'ENGAGE_JWT_SECRET=' . ($jwtSecret === ''
    ? '(EMPTY — login returns 503)'
    : '*** (' . strlen($jwtSecret) . ' chars' . (strlen($jwtSecret) < 32 ? ', TOO SHORT' : '') . ')') . "\n";
echo 'ENGAGE_SERVICE_KEY=' . ($svcKey === '' ? '(EMPTY — Console callbacks will 401)' : '*** (' . strlen($svcKey) . " chars)") . "\n";
echo 'CONSOLE_INBOUND_KEY=' . ($consoleKey === '' ? '(EMPTY — outbound audit will 401 at Console)' : '*** (' . strlen($consoleKey) . " chars)") . "\n";
echo 'REACH_INBOUND_TOKEN=' . ($reachKey === '' ? '(EMPTY — Reach ingest will 401)' : '*** (' . strlen($reachKey) . " chars)") . "\n";

echo "\n--- ENGAGE_DB_* (from .env) ---\n";
foreach ($vars as $key => $val) {
    if ($key === 'ENGAGE_DB_PASSWORD') {
        echo $key . '=*** (' . strlen($val) . " chars)\n";
    } else {
        $shown = $val === '' ? '(EMPTY — set in api/.env on the server)' : $val;
        echo $key . '=' . $shown . "\n";
    }
}

$missing = [];
foreach (['ENGAGE_DB_HOST', 'ENGAGE_DB_NAME', 'ENGAGE_DB_USER', 'ENGAGE_DB_PASSWORD'] as $key) {
    if ($vars[$key] === '') {
        $missing[] = $key;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "\nERROR: missing " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Fix api/.env on the server (copy from .env.example if needed).\n");
    exit(1);
}

if (! extension_loaded('pdo_pgsql')) {
    exit(1);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $vars['ENGAGE_DB_HOST'],
    $vars['ENGAGE_DB_PORT'],
    $vars['ENGAGE_DB_NAME'],
);

echo "\n--- PDO test ---\n";

try {
    $pdo = new PDO($dsn, $vars['ENGAGE_DB_USER'], $vars['ENGAGE_DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->query('SELECT 1');
    echo "PDO OK\n";

    if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
        fwrite(STDERR, "WARNING: ENGAGE_JWT_SECRET missing or < 32 chars — auth/login will fail.\n");
        fwrite(STDERR, "Add to api/.env: ENGAGE_JWT_SECRET=" . bin2hex(random_bytes(32)) . "\n");
        exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'PDO FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

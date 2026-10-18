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

echo "\n--- Seller programme (docs/SELLER_PROGRAM.md) ---\n";
foreach ([
    'SELLER_PORTAL_KEY'   => [32, 'seller.aicountly.com gets 503'],
    'ENGAGE_DATA_KEY'     => [64, 'seller KYC cannot be stored (503); 64 hex chars or base64 of 32 bytes'],
    'REACH_OUTCOME_TOKEN' => [32, 'lead outcomes stay queued, not sent to Reach'],
    'FINANCE_OUTBOUND_KEY' => [32, 'weekly payout runs fail closed (nothing sent to Finance)'],
    'ENGAGE_INBOUND_FINANCE_KEY' => [32, 'Finance payout status updates get 503'],
] as $k => [$min, $why]) {
    $v = $read($k);
    echo $k . '=' . ($v === '' ? "(EMPTY — {$why})" : '*** (' . strlen($v) . ' chars' . (strlen($v) < $min && $k !== 'ENGAGE_DATA_KEY' ? ', TOO SHORT' : '') . ')') . "\n";
}
foreach (['REACH_API_BASE_URL', 'FINANCE_API_BASE_URL', 'ENGAGE_SELLER_MAILER', 'ENGAGE_SELLER_DOCS_PATH', 'SELLER_PORTAL_BASE_URL'] as $k) {
    $v = $read($k);
    echo $k . '=' . ($v === '' ? '(empty — see .env.example)' : $v) . "\n";
}

echo "\n--- Licensing: Manage read API (docs/LICENSING.md § 7; optional — dormant when empty) ---\n";
$s2sEnv = $read('ENGAGE_S2S_ENVIRONMENT');
echo 'ENGAGE_S2S_ENVIRONMENT=' . ($s2sEnv === '' ? '(empty — Manage routes answer 503 inbound_not_configured)' : $s2sEnv) . "\n";
$keyIds = array_values(array_filter(array_map('trim', explode(',', $read('ENGAGE_INBOUND_MANAGE_KEY_IDS'))), 'strlen'));
echo 'ENGAGE_INBOUND_MANAGE_KEY_IDS=' . ($keyIds === [] ? '(empty — Manage routes answer 503 inbound_not_configured)' : implode(',', $keyIds)) . "\n";
foreach ($keyIds as $id) {
    $name = 'ENGAGE_INBOUND_MANAGE_SECRET_' . preg_replace('/[^A-Z0-9]/', '_', strtoupper($id));
    $v = $read($name);
    echo $name . '=' . ($v === '' ? '(EMPTY — this key id is not live)' : '*** (' . strlen($v) . ' bytes' . (strlen($v) < 32 ? ', TOO SHORT — not live' : '') . ')') . "\n";
}

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

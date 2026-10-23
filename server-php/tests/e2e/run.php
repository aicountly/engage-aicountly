<?php
declare(strict_types=1);

/**
 * End-to-end proof of the plans / licensing / coupons chain across Engage, Manage and my.aicountly (integration
 * branch claude/rectify-2026-10). Real servers (php -S), real PostgreSQL, real signed AIC-S2S calls, the real CLIs —
 * nothing faked except Manage's portal session check (Manage's own test router answers sessions "ses-<uuid>").
 *
 *   a) staff publish a Basic (default) offer per product and a paid offer          (Engage staff API)
 *   b) my.aicountly's sender provisions a new holder → Basic licences               (accounts:engage-provision)
 *   c) Engage pushes → Manage stores; companyinfo?app=books shows the Basic plan    (engage:entitlements-push)
 *   d) staff quote with a coupon (percent + cap), paid licence with quote_ref → redemption committed, push → paid plan
 *   e) Manage PlanGate: 422 PLAN_LIMIT_REACHED beyond max_companies; a member's creation in the owner's workspace
 *      consumes the owner's limit
 *   f) expiry (engage:licences-expire, past end) → Basic pushed → Manage falls back to Basic, ghm_subscription untouched
 *   g) Manage coupon preview and upgrade request against live Engage (quote key)
 *   h) Manage plans_sync pulls the Basic defaults from Engage's catalogue; reconcile reports no drift
 *
 * See tests/e2e/README.md. Skips (exit 0) when the environment is not set up; exit 1 on any failure.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$engageDir = dirname(__DIR__, 2);                      // engage-aicountly/server-php
$manageDir = rtrim((string) getenv('E2E_MANAGE_DIR'), '/');   // manage-aicountly checkout
$myaicDir  = rtrim((string) getenv('E2E_MYAIC_DIR'), '/');    // my-aicountly-com checkout
$pgHost    = (string) (getenv('E2E_PG_HOST') ?: '127.0.0.1');
$pgPort    = (int) (getenv('E2E_PG_PORT') ?: 5432);
$pgUser    = (string) (getenv('E2E_PG_USER') ?: 'postgres');
$pgPass    = (string) getenv('E2E_PG_PASSWORD');
$pgSocket  = rtrim((string) getenv('E2E_PG_SOCKET_DIR'), '/'); // only when E2E_PG_PORT ≠ 5432 (my.aicountly pins 5432)

$skip = static function (string $why): never {
    echo "SKIP end-to-end: {$why}\n";
    exit(0);
};
if (!extension_loaded('pdo_pgsql') || !extension_loaded('curl')) {
    $skip('pdo_pgsql and curl are required');
}
if ($manageDir === '' || !is_file($manageDir . '/server-php/tests/support/manage_http_router.php')) {
    $skip('set E2E_MANAGE_DIR to a manage-aicountly checkout of the integration branch');
}
if ($myaicDir === '' || !is_file($myaicDir . '/server-php/spark') || !is_file($myaicDir . '/server-php/app/Commands/EngageProvision.php')) {
    $skip('set E2E_MYAIC_DIR to a my-aicountly-com checkout with accounts:engage-provision');
}
if (!is_file($engageDir . '/vendor/autoload.php')) {
    $skip('run composer install in engage-aicountly/server-php first');
}
if ($pgPort !== 5432 && ($pgSocket === '' || !file_exists("{$pgSocket}/.s.PGSQL.{$pgPort}"))) {
    $skip('my.aicountly connects on port 5432 only: set E2E_PG_SOCKET_DIR to the server\'s socket directory (a .s.PGSQL.5432 link is made there)');
}
try {
    $admin = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname=postgres", $pgUser, $pgPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    $skip('PostgreSQL not reachable (' . $e->getMessage() . ')');
}
require $engageDir . '/vendor/autoload.php';

// ------------------------------------------------------------------------------------------------ helpers
$passed = 0;
$failed = 0;
$log = [];
function ok(bool $c, string $label, mixed $detail = null): bool
{
    global $passed, $failed;
    $c ? $passed++ : $failed++;
    echo ($c ? '  OK   ' : '  FAIL ') . $label . ($detail !== null && !$c ? '  → ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES)) : '') . "\n";

    return $c;
}
function eq(mixed $want, mixed $got, string $label): bool
{
    return ok($want === $got, $label . ' = ' . json_encode($got, JSON_UNESCAPED_SLASHES), 'expected ' . json_encode($want, JSON_UNESCAPED_SLASHES));
}
function step(string $t): void
{
    echo "\n== {$t}\n";
}
/** @return array{status:int, json:mixed, body:string} */
function http(string $method, string $url, array $headers = [], mixed $json = null): array
{
    $ch = curl_init($url);
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = "{$k}: {$v}";
    }
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
        $lines[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $lines;
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode($body, true), 'body' => $body];
}
/** Run a CLI with extra env; returns [exit, output]. */
function cli(array $cmd, string $cwd, array $env): array
{
    global $log;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env + getenv());
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($p);
    $line = '$ (cd ' . basename(dirname($cwd)) . '/' . basename($cwd) . ' && ' . implode(' ', $cmd) . ')  → exit ' . $code;
    $log[] = $line . "\n" . trim((string) $out);
    echo "  $ {$line}\n" . preg_replace('/^/m', '      ', trim((string) $out)) . "\n";

    return [$code, (string) $out];
}
function freePort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
    fclose($s);

    return $port;
}
/** @return resource */
function serve(int $port, string $docroot, string $router, array $env, string $logFile)
{
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes, $docroot, $env + getenv());
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
        if ($c) {
            fclose($c);
            break;
        }
        usleep(100000);
    }

    return $p;
}
$key = static fn (): string => bin2hex(random_bytes(32)); // 64 chars ≥ 32 bytes

// ------------------------------------------------------------------------------------------------ setup
$run = 'e2e' . bin2hex(random_bytes(3));
$tmp = sys_get_temp_dir() . "/aic-{$run}";
mkdir($tmp, 0700, true);
$dbs = ['engage' => "{$run}_engage", 'manage' => "{$run}_manage", 'myaic' => "{$run}_myaic"];
foreach ($dbs as $db) {
    $admin->exec("CREATE DATABASE {$db}");
}
$madeLink = null;
if ($pgPort !== 5432 && !file_exists("{$pgSocket}/.s.PGSQL.5432")) {
    symlink("{$pgSocket}/.s.PGSQL.{$pgPort}", "{$pgSocket}/.s.PGSQL.5432");
    $madeLink = "{$pgSocket}/.s.PGSQL.5432";
}
$procs = [];
register_shutdown_function(static function () use (&$procs, $admin, $dbs, $tmp, &$madeLink): void {
    foreach ($procs as $p) {
        $st = proc_get_status($p);
        if ($st['running']) {
            posix_kill($st['pid'], SIGTERM);
        }
        proc_close($p);
    }
    if (getenv('E2E_KEEP') !== '1') {
        foreach ($dbs as $db) {
            try {
                $admin->exec("DROP DATABASE IF EXISTS {$db} WITH (FORCE)");
            } catch (Throwable $e) {
            }
        }
        exec('rm -rf ' . escapeshellarg($tmp));
    }
    if ($madeLink !== null) {
        @unlink($madeLink);
    }
});

$pE = freePort();
$pM = freePort();
$engageUrl = "http://127.0.0.1:{$pE}/api";
$manageUrl = "http://127.0.0.1:{$pM}/api";
$env = 'local';
$k = [
    'push' => ['id' => 'engage-push-e2e', 'secret' => $key()], 'mread' => ['id' => 'engage-read-e2e', 'secret' => $key()],
    'read' => ['id' => 'manage-read-e2e', 'secret' => $key()], 'quote' => ['id' => 'manage-quote-e2e', 'secret' => $key()],
    'prov' => ['id' => 'myaic-issue-e2e', 'secret' => $key()],
];
$up = static fn (string $id): string => strtoupper(str_replace('-', '_', $id));
$jwtSecret = $key();

$engageEnv = [
    'CI_ENVIRONMENT' => 'production', 'app.baseURL' => "{$engageUrl}/", 'app.indexPage' => '',
    'ENGAGE_DB_HOST' => $pgHost, 'ENGAGE_DB_PORT' => (string) $pgPort, 'ENGAGE_DB_NAME' => $dbs['engage'],
    'ENGAGE_DB_USER' => $pgUser, 'ENGAGE_DB_PASSWORD' => $pgPass,
    'ENGAGE_JWT_SECRET' => $jwtSecret, 'ENGAGE_JWT_TTL_MINUTES' => '120', 'CONTROLLER_APP_CODE' => 'engage',
    'CONSOLE_API_URL' => 'http://127.0.0.1:9', 'CONSOLE_API_BASE_URL' => 'http://127.0.0.1:9', 'CONSOLE_CRON_MONITOR_KEY' => '',
    'ENGAGE_S2S_ENVIRONMENT' => $env,
    'MANAGE_API_BASE_URL' => $manageUrl,
    'ENGAGE_OUTBOUND_MANAGE_KEY_ID' => $k['push']['id'], 'ENGAGE_OUTBOUND_MANAGE_SECRET' => $k['push']['secret'],
    'ENGAGE_OUTBOUND_MANAGE_READ_KEY_ID' => $k['mread']['id'], 'ENGAGE_OUTBOUND_MANAGE_READ_SECRET' => $k['mread']['secret'],
    'ENGAGE_INBOUND_MANAGE_KEY_IDS' => $k['read']['id'] . ',' . $k['quote']['id'],
    'ENGAGE_INBOUND_MANAGE_SECRET_' . $up($k['read']['id']) => $k['read']['secret'],
    'ENGAGE_INBOUND_MANAGE_SCOPE_' . $up($k['read']['id']) => 'read',
    'ENGAGE_INBOUND_MANAGE_SECRET_' . $up($k['quote']['id']) => $k['quote']['secret'],
    'ENGAGE_INBOUND_MANAGE_SCOPE_' . $up($k['quote']['id']) => 'quote',
    'ENGAGE_INBOUND_MYAICOUNTLY_KEY_IDS' => $k['prov']['id'],
    'ENGAGE_INBOUND_MYAICOUNTLY_SECRET_' . $up($k['prov']['id']) => $k['prov']['secret'],
    'ENGAGE_INBOUND_MYAICOUNTLY_SCOPE_' . $up($k['prov']['id']) => 'issue',
];
$manageEnv = [
    'MANAGE_TEST_SESSIONS' => 'ses-7000000001=7000000001,ses-7000000002=7000000002,ses-7000000003=7000000003',
    'MANAGE_TEST_PGSQL_DSN' => "pgsql:host={$pgHost};port={$pgPort};dbname={$dbs['manage']}",
    'MANAGE_TEST_PGSQL_USER' => $pgUser, 'MANAGE_TEST_PGSQL_PASSWORD' => $pgPass, 'MANAGE_TEST_PGSQL_SCHEMA' => '',
    'DB_HOST' => $pgHost, 'DB_PORT' => (string) $pgPort, 'DB_NAME' => $dbs['manage'], 'DB_USER' => $pgUser, 'DB_PASSWORD' => $pgPass,
    'MANAGE_S2S_ENVIRONMENT' => $env,
    'ENGAGE_API_BASE_URL' => $engageUrl,
    'MANAGE_S2S_ENGAGE_KEY_ID' => $k['read']['id'], 'MANAGE_S2S_ENGAGE_SECRET' => $k['read']['secret'],
    'MANAGE_S2S_ENGAGE_QUOTE_KEY_ID' => $k['quote']['id'], 'MANAGE_S2S_ENGAGE_QUOTE_SECRET' => $k['quote']['secret'],
    'MANAGE_INBOUND_ENGAGE_KEY_IDS' => $k['push']['id'] . ',' . $k['mread']['id'],
    'MANAGE_INBOUND_ENGAGE_SECRET_' . $up($k['push']['id']) => $k['push']['secret'],
    'MANAGE_INBOUND_ENGAGE_SCOPE_' . $up($k['push']['id']) => 'push',
    'MANAGE_INBOUND_ENGAGE_SECRET_' . $up($k['mread']['id']) => $k['mread']['secret'],
    'MANAGE_INBOUND_ENGAGE_SCOPE_' . $up($k['mread']['id']) => 'read',
    'MANAGE_BILLING_RATE_DIR' => "{$tmp}/rate", 'AICOUNTLY_AUTH_URL' => 'http://127.0.0.1:9', 'BOOKS_API_BASE' => 'http://127.0.0.1:9',
    'CONSOLE_CRON_MONITOR_KEY' => '', 'FINANCE_API_BASE_URL' => '', 'EMAIL_API_KEY' => '',
];
$myaicEnv = [
    'CI_ENVIRONMENT' => 'production',
    'DB_UUID_HOST' => $pgPort === 5432 ? $pgHost : $pgSocket, 'DB_UUID_USER' => $pgUser, 'DB_UUID_PASS' => $pgPass !== '' ? $pgPass : 'unused',
    'DB_UUID_NAME' => $dbs['myaic'],
    'ENGAGE_API_BASE_URL' => $engageUrl, 'MYAICOUNTLY_S2S_ENGAGE_KEY_ID' => $k['prov']['id'],
    'MYAICOUNTLY_S2S_ENGAGE_SECRET' => $k['prov']['secret'], 'MYAICOUNTLY_S2S_ENVIRONMENT' => $env,
];
$H = '7000000001'; // holder: the account owner (my.aicountly uuid)
$M = '7000000002'; // a member of H's workspace (also signed up: provisioned with Basic too)
$N = '7000000003'; // a person Manage has no pushed plan for (not provisioned)

step("setup ({$run}): databases, schema, migrations, servers");
[$c] = cli([PHP_BINARY, 'spark', 'migrate'], $engageDir, $engageEnv);
ok($c === 0, 'Engage migrations');
foreach (['RolesSeeder', 'ProductsSeeder'] as $seeder) {
    [$c] = cli([PHP_BINARY, 'spark', 'db:seed', $seeder], $engageDir, $engageEnv);
    ok($c === 0, "Engage {$seeder}");
}
$mpdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$dbs['manage']}", $pgUser, $pgPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mpdo->exec((string) preg_replace('/^\s*GRANT\b[^;]*;/ims', '', (string) file_get_contents($manageDir . '/server-php/database/schema.sql')));
[$c] = cli([PHP_BINARY, 'scripts/migrate.php', '--run'], $manageDir . '/server-php', $manageEnv);
ok($c === 0, 'Manage schema.sql + migrations (through 024)');
$ypdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$dbs['myaic']}", $pgUser, $pgPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ypdo->exec('CREATE TABLE useraictly (uuid BIGINT PRIMARY KEY, user_regdemail VARCHAR(255), user_firstname VARCHAR(100), user_midname VARCHAR(100), user_lastname VARCHAR(100), useraictly_status INTEGER NOT NULL)');
[$c] = cli([PHP_BINARY, 'spark', 'accounts:migrate-engage-provisioning'], $myaicDir . '/server-php', $myaicEnv);
ok($c === 0, 'my.aicountly aicengageprov outbox table');
$epdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$dbs['engage']}", $pgUser, $pgPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

file_put_contents("{$tmp}/engage_router.php", '<?php $_SERVER["SCRIPT_NAME"] = "/api/index.php"; $_SERVER["SCRIPT_FILENAME"] = ' . var_export($engageDir . '/index.php', true) . '; require ' . var_export($engageDir . '/index.php', true) . ';');
$procs[] = serve($pE, $engageDir, "{$tmp}/engage_router.php", $engageEnv + ['PHP_CLI_SERVER_WORKERS' => '4'], "{$tmp}/engage.log");
$procs[] = serve($pM, $manageDir . '/server-php/public', $manageDir . '/server-php/tests/support/manage_http_router.php', $manageEnv + ['PHP_CLI_SERVER_WORKERS' => '4'], "{$tmp}/manage.log");
$h = http('GET', "{$engageUrl}/health");
ok($h['status'] === 200, "Engage up on :{$pE} (/api/health)", $h['body']);
$h = http('GET', "{$manageUrl}/health");
ok($h['status'] === 200, "Manage up on :{$pM} (/api/health)", $h['body']);

// Staff (Engage JWT, as Engage issues it): maker = sales_manager, checker = super_admin (four-eyes on coupons).
$staff = static function (string $email, string $role) use ($epdo, $jwtSecret): string {
    $epdo->prepare("INSERT INTO engage_users (email, name, password_hash, status) VALUES (?, ?, 'x', 'active')")->execute([$email, $email]);
    $uid = (int) $epdo->lastInsertId();
    $rid = $epdo->query('SELECT id FROM engage_roles WHERE code = ' . $epdo->quote($role))->fetchColumn();
    if ($rid === false) {
        $epdo->prepare('INSERT INTO engage_roles (code, name) VALUES (?, ?)')->execute([$role, $role]);
        $rid = $epdo->lastInsertId();
    }
    $epdo->prepare('INSERT INTO engage_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$uid, (int) $rid]);

    return \Firebase\JWT\JWT::encode(['iss' => 'engage.aicountly.org', 'aud' => 'engage-portal', 'iat' => time(), 'nbf' => time(),
        'exp' => time() + 3600, 'sub' => (string) $uid, 'email' => $email, 'roles' => [$role]], $jwtSecret, 'HS256');
};
$maker = $staff('maker@e2e.test', 'sales_manager');
$checker = $staff('checker@e2e.test', 'super_admin');
$as = static fn (string $tok): array => ['Authorization' => "Bearer {$tok}"];
$sess = static fn (string $uuid): array => ['Authorization' => "Bearer ses-{$uuid}"];

// ------------------------------------------------------------------------------------------------ a)
step('a) staff publish a Basic default offer per product and a paid offer');
foreach ([['basic', 'complimentary'], ['standard', 'paid']] as [$code, $kind]) {
    $r = http('POST', "{$engageUrl}/v1/licence-types", $as($maker), ['code' => $code, 'label' => ucfirst($code), 'lifecycle_kind' => $kind]);
    ok($r['status'] === 201, "licence type {$code} ({$kind})", $r['body']);
}
$basicEnts = [
    'books' => [['code' => 'max_companies', 'label' => 'Companies', 'value' => 1, 'unit' => 'count'], ['code' => 'max_users', 'label' => 'Users', 'value' => 2, 'unit' => 'count']],
    'inventory' => [['code' => 'inventory.max_warehouses', 'label' => 'Warehouses', 'value' => 1, 'unit' => 'count']],
    'billing' => [['code' => 'billing.max_bills_per_month', 'label' => 'Bills a month', 'value' => 50, 'unit' => 'count']],
    'notes' => [['code' => 'notes.max_notes', 'label' => 'Notes', 'value' => 100, 'unit' => 'count']],
    'docs' => [['code' => 'drive.storage_mb', 'label' => 'Storage (MB)', 'value' => 512, 'unit' => 'MB']],
];
$publish = static function (array $body) use ($engageUrl, $as, $maker): ?int {
    $r = http('POST', "{$engageUrl}/v1/offers", $as($maker), $body);
    if (!ok($r['status'] === 201, "offer {$body['offer_code']} created", $r['body'])) {
        return null;
    }
    $id = (int) $r['json']['data']['id'];
    $p = http('POST', "{$engageUrl}/v1/offers/{$id}/publish", $as($maker));
    ok($p['status'] === 200, "offer {$body['offer_code']} published" . (!empty($body['is_default']) ? ' as the product\'s Basic (default)' : ''), $p['body']);

    return $id;
};
foreach ($basicEnts as $product => $ents) {
    $publish(['offer_code' => "{$product}-basic", 'product_code' => $product, 'licence_type_code' => 'basic', 'label' => 'Basic',
        'price_paise' => 0, 'price_basis' => 'inclusive', 'gst_rate' => '0', 'term_months' => 0, 'term_days' => 0,
        'entitlements' => $ents, 'is_default' => true]);
}
$publish(['offer_code' => 'books-pro', 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => 'Books Pro',
    'price_paise' => 1000000, 'price_basis' => 'exclusive', 'gst_rate' => '18', 'sac' => '997331', 'term_months' => 12, 'term_days' => 0, 'seats' => 5,
    'entitlements' => [['code' => 'max_companies', 'label' => 'Companies', 'value' => 3, 'unit' => 'count'], ['code' => 'max_users', 'label' => 'Users', 'value' => 5, 'unit' => 'count'],
        ['code' => 'feature.books.gst_einvoice', 'label' => 'E-invoice', 'value' => true, 'unit' => 'bool']]]);
eq(5, (int) $epdo->query("SELECT COUNT(*) FROM engage_offers WHERE is_default AND status = 'published'")->fetchColumn(), 'published default offers (books, inventory, billing, notes, docs)');

// ------------------------------------------------------------------------------------------------ b)
step('b) my.aicountly sender provisions a new holder → Engage Basic licences');
$ypdo->exec("INSERT INTO useraictly (uuid, user_regdemail, user_firstname, user_lastname, useraictly_status) VALUES ({$H}, 'asha@e2e.test', 'Asha', 'Owner', 1), ({$M}, 'ravi@e2e.test', 'Ravi', 'Member', 1)");
[$c, $out] = cli([PHP_BINARY, 'spark', 'accounts:engage-provision'], $myaicDir . '/server-php', $myaicEnv);
ok($c === 0 && str_contains($out, 'delivered'), 'accounts:engage-provision ran (sweep + signed send)');
eq(['delivered', 'delivered'], array_column($ypdo->query('SELECT status FROM aicengageprov ORDER BY uuid')->fetchAll(), 'status'), 'my.aicountly outbox rows');
$basics = $epdo->query("SELECT product_code FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic AND status = 'active' ORDER BY product_code")->fetchAll(PDO::FETCH_COLUMN);
eq(['billing', 'books', 'docs', 'inventory', 'notes'], $basics, "Engage: live Basic licences for holder {$H}");
[$c] = cli([PHP_BINARY, 'spark', 'accounts:engage-provision'], $myaicDir . '/server-php', $myaicEnv);
eq(5, (int) $epdo->query("SELECT COUNT(*) FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic")->fetchColumn(), 'a second run changes nothing (idempotent)');

// ------------------------------------------------------------------------------------------------ c)
step('c) engage:entitlements-push → Manage stores; companyinfo?app=books shows the Basic plan');
[$c, $out] = cli([PHP_BINARY, 'spark', 'engage:entitlements-push'], $engageDir, $engageEnv);
ok($c === 0, 'push ran');
eq(0, (int) $epdo->query("SELECT COUNT(*) FROM engage_entitlement_outbox WHERE status <> 'delivered'")->fetchColumn(), 'Engage outbox: everything delivered');
$rows = $mpdo->query("SELECT app_key, status, plan->>'code' AS plan, (plan->>'is_default')::boolean AS d FROM ghm_plan_entitlement WHERE holder_uuid = '{$H}' ORDER BY app_key")->fetchAll();
eq(['billing:billing-basic', 'books:books-basic', 'docs:docs-basic', 'inventory:inventory-basic', 'notes:notes-basic'],
    array_map(static fn (array $r): string => $r['app_key'] . ':' . $r['plan'], $rows), 'Manage ghm_plan_entitlement for the holder');
$base = ['constitution_type' => 'private_limited', 'fy_start' => '2026-04-01', 'fy_end' => '2027-03-31'];
$r = http('POST', "{$manageUrl}/companies/create", $sess($H), ['comp_name' => 'Asha Traders One'] + $base);
$c1 = (int) ($r['json']['company_id'] ?? $r['json']['data']['company_id'] ?? 0);
ok($c1 > 0, "holder creates company #{$c1} (1 of Basic's max_companies 1)", $r['body']);
$info = http('GET', "{$manageUrl}/companyinfo?cmp_id={$c1}&app=books", $sess($H));
$plan = $info['json']['data']['plan'] ?? null;
eq(['books-basic', true, 'active', ['max_companies' => 1, 'max_users' => 2]], [$plan['code'] ?? null, $plan['is_default'] ?? null, $plan['status'] ?? null, $plan['entitlements'] ?? null], 'companyinfo?app=books plan');
$r = http('POST', "{$manageUrl}/companies/create", $sess($H), ['comp_name' => 'Asha Traders Two'] + $base);
eq([422, 'PLAN_LIMIT_REACHED', ['code' => 'max_companies', 'limit' => 1, 'used' => 1, 'app' => 'books']], [$r['status'], $r['json']['error']['code'] ?? null, $r['json']['error']['details'] ?? null], 'Basic: a second company is refused');

// ------------------------------------------------------------------------------------------------ d)
step('d) staff quote with a coupon (percent + cap) → paid licence with quote_ref → redemption committed → push');
$r = http('POST', "{$engageUrl}/v1/coupons", $as($maker), ['code_kind' => 'shared', 'label' => 'E2E 20 % (max ₹1,500)', 'discount_type' => 'percent',
    'percent_bp' => 2000, 'max_discount_paise' => 150000, 'code' => 'SAVEBIG', 'max_redemptions_per_holder' => 3, 'applies_to' => ['product_codes' => ['books']]]);
ok($r['status'] === 201, 'maker drafts coupon SAVEBIG', $r['body']);
$couponId = (int) ($r['json']['data']['id'] ?? 0);
$r = http('POST', "{$engageUrl}/v1/coupons/{$couponId}/activate", $as($maker));
eq(true, $r['status'] >= 400, 'the maker cannot activate their own coupon (four-eyes) — HTTP ' . $r['status']);
$r = http('POST', "{$engageUrl}/v1/coupons/{$couponId}/activate", $as($checker));
eq([200, 'active'], [$r['status'], $r['json']['data']['status'] ?? null], 'checker activates');
$q = http('POST', "{$engageUrl}/v1/quotes", $as($maker), ['holder_uuid' => $H, 'offer_code' => 'books-pro', 'coupon_code' => 'savebig']);
$qd = $q['json']['data'] ?? [];
ok($q['status'] === 201, 'staff quote', $q['body']);
eq([1000000, 150000, 850000, 153000, 1003000], [$qd['list_paise'] ?? null, $qd['discount_paise'] ?? null, $qd['net_paise'] ?? null, $qd['gst_paise'] ?? null, $qd['total_paise'] ?? null],
    'list ₹10,000 − 20 % capped at ₹1,500 = ₹8,500 + GST 18 % on the net ₹1,530 = ₹10,030');
eq(['amount_paise' => 850000, 'list_amount_paise' => 1000000, 'discount_paise' => 150000, 'discount_ref' => 'SAVEBIG'],
    array_intersect_key($qd['finance_line'] ?? [], array_flip(['amount_paise', 'list_amount_paise', 'discount_paise', 'discount_ref'])), 'finance_line (what Finance\'s invoice line takes)');
$quoteRef = (string) ($qd['quote_ref'] ?? '');
$epdo->exec("INSERT INTO engage_accounts (name, status) VALUES ('Asha Traders', 'customer')");
$accountId = (int) $epdo->lastInsertId();
$r = http('POST', "{$engageUrl}/v1/licences", $as($maker) + ['Idempotency-Key' => 'e2e-issue-' . $run], ['account_id' => $accountId, 'holder_uuid' => $H,
    'holder_note' => 'E2E: owner confirmed', 'offer_code' => 'books-pro', 'activate' => true, 'quote_ref' => $quoteRef, 'invoice_refs' => 'AIC-26-27-000001']);
$paidRef = (string) ($r['json']['data']['licence_ref'] ?? '');
ok($r['status'] === 201 && $paidRef !== '', "paid licence {$paidRef} issued with quote_ref {$quoteRef}", $r['body']);
$red = $epdo->query("SELECT state, licence_ref, net_paise FROM engage_coupon_redemptions WHERE quote_ref = " . $epdo->quote($quoteRef))->fetch();
eq(['committed', $paidRef, 850000], [$red['state'] ?? null, $red['licence_ref'] ?? null, (int) ($red['net_paise'] ?? 0)], 'redemption committed with the licence (same transaction)');
eq('superseded', $epdo->query("SELECT status FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic AND product_code = 'books'")->fetchColumn(), 'the holder\'s Books Basic is superseded');
cli([PHP_BINARY, 'spark', 'engage:entitlements-push'], $engageDir, $engageEnv);
$plan = http('GET', "{$manageUrl}/companyinfo?cmp_id={$c1}&app=books", $sess($H))['json']['data']['plan'] ?? null;
eq(['books-pro', false, ['max_companies' => 3, 'max_users' => 5, 'feature.books.gst_einvoice' => true]], [$plan['code'] ?? null, $plan['is_default'] ?? null, $plan['entitlements'] ?? null], 'Manage companyinfo?app=books: the paid plan');

// ------------------------------------------------------------------------------------------------ e)
step('e) PlanGate: max_companies of the owner, also for a member creating in the owner\'s workspace');
$r = http('POST', "{$manageUrl}/companies/create", $sess($H), ['comp_name' => 'Asha Traders Two'] + $base);
$c2 = (int) ($r['json']['company_id'] ?? 0);
ok($c2 > 0, "owner creates #{$c2} (2 of 3)", $r['body']);
$t = http('GET', "{$manageUrl}/v1/company-creation-targets", $sess($H));
$W = (int) ($t['json']['data'][0]['workspace_id'] ?? 0);
eq([true, 1], [$t['json']['data'][0]['is_own'] ?? null, $t['json']['data'][0]['companies_remaining'] ?? null], 'owner\'s targets: own workspace, 1 left');
$r = http('POST', "{$manageUrl}/v1/workspaces/{$W}/members", $sess($H), ['uuid' => $M, 'name' => 'Ravi Member', 'email' => 'ravi@e2e.test',
    'settings' => ['can_create_companies' => true, 'max_companies_created' => 5, 'creator_roles' => ['books' => ['accountant']]]]);
ok($r['status'] === 200 || $r['status'] === 201, 'owner invites the member with "may create companies"', $r['body']);
$r = http('POST', "{$manageUrl}/v1/workspaces/{$W}/accept", $sess($M));
ok($r['status'] === 200, 'member accepts', $r['body']);
$t = http('GET', "{$manageUrl}/v1/company-creation-targets", $sess($M));
$shared = array_values(array_filter($t['json']['data'] ?? [], static fn (array $x): bool => !$x['is_own']))[0] ?? [];
eq([$W, true, 1], [$shared['workspace_id'] ?? null, $shared['can_create'] ?? null, $shared['companies_remaining'] ?? null], 'member\'s target: the owner\'s workspace, min(member cap 5, owner plan 1) = 1');
$r = http('POST', "{$manageUrl}/companies/create", $sess($M), ['comp_name' => 'Ravi Made For Asha', 'workspace_id' => $W, 'creator_access' => [['app' => 'books', 'role_key' => 'accountant']]] + $base);
$c3 = (int) ($r['json']['company_id'] ?? 0);
eq([true, false], [$c3 > 0, $r['json']['owner']['is_me'] ?? null], "member creates #{$c3} in the owner's workspace (owned by the owner)");
eq($H, $mpdo->query("SELECT owner_uuid FROM ghm_company WHERE cmp_id = {$c3}")->fetchColumn(), '… owner_uuid is the workspace owner');
$r = http('POST', "{$manageUrl}/companies/create", $sess($H), ['comp_name' => 'Asha Traders Four'] + $base);
eq([422, 'PLAN_LIMIT_REACHED', ['code' => 'max_companies', 'limit' => 3, 'used' => 3, 'app' => 'books']], [$r['status'], $r['json']['error']['code'] ?? null, $r['json']['error']['details'] ?? null],
    'owner\'s 4th: 422 PLAN_LIMIT_REACHED (the member\'s creation used the owner\'s slot)');
$r = http('POST', "{$manageUrl}/companies/create", $sess($M), ['comp_name' => 'Ravi Second For Asha', 'workspace_id' => $W, 'creator_access' => [['app' => 'books', 'role_key' => 'accountant']]] + $base);
eq([422, 'PLAN_LIMIT_REACHED'], [$r['status'], $r['json']['error']['code'] ?? null], 'member\'s next creation there: 422 PLAN_LIMIT_REACHED');
$r = http('POST', "{$manageUrl}/companies/create", $sess($M), ['comp_name' => 'Ravi Own Company'] + $base);
ok(($r['json']['company_id'] ?? 0) > 0, 'the member\'s own workspace uses the member\'s OWN plan (their Basic: 1 company, none used yet)', $r['body']);

// ------------------------------------------------------------------------------------------------ f)
step('f) expiry → Basic pushed → Manage falls back to Basic; ghm_subscription untouched');
$subsBefore = (int) $mpdo->query('SELECT (SELECT COUNT(*) FROM ghm_subscription) + (SELECT COUNT(*) FROM ghm_subscription_event)')->fetchColumn();
$epdo->exec("UPDATE engage_licences SET starts_at = NOW() - INTERVAL '400 days', ends_at = NOW() - INTERVAL '1 day' WHERE licence_ref = " . $epdo->quote($paidRef));
[$c] = cli([PHP_BINARY, 'spark', 'engage:licences-expire'], $engageDir, $engageEnv);
eq('expired', $epdo->query('SELECT status FROM engage_licences WHERE licence_ref = ' . $epdo->quote($paidRef))->fetchColumn(), 'paid licence expired');
eq('active', $epdo->query("SELECT status FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic AND product_code = 'books'")->fetchColumn(), 'Books Basic reactivated');
cli([PHP_BINARY, 'spark', 'engage:entitlements-push'], $engageDir, $engageEnv);
$plan = http('GET', "{$manageUrl}/companyinfo?cmp_id={$c1}&app=books", $sess($H))['json']['data'] ?? [];
eq(['books-basic', true, 'active'], [$plan['plan']['code'] ?? null, $plan['plan']['is_default'] ?? null, $plan['lifecycle'] ?? null], 'Manage: back on Basic; company lifecycle still active');
eq($subsBefore, (int) $mpdo->query('SELECT (SELECT COUNT(*) FROM ghm_subscription) + (SELECT COUNT(*) FROM ghm_subscription_event)')->fetchColumn(), 'ghm_subscription / _event untouched');
eq(3, (int) $mpdo->query("SELECT COUNT(*) FROM ghm_company WHERE owner_uuid = '{$H}' AND cmp_status = 1")->fetchColumn(), 'over Basic\'s limit: nothing deleted or restricted (3 companies stay active)');
$r = http('POST', "{$manageUrl}/companies/create", $sess($H), ['comp_name' => 'Asha Traders Five'] + $base);
eq([422, 1, 3], [$r['status'], $r['json']['error']['details']['limit'] ?? null, $r['json']['error']['details']['used'] ?? null], '… only a NEW company is refused');

// ------------------------------------------------------------------------------------------------ g)
step('g) Manage coupon preview and upgrade request against live Engage');
$r = http('POST', "{$manageUrl}/billing/coupons/preview?cmp_id={$c1}", $sess($H), ['offer_code' => 'books-pro', 'code' => 'SAVEBIG']);
$d = $r['json']['data'] ?? [];
eq([200, true, 150000, 850000, 1003000], [$r['status'], $d['valid'] ?? null, $d['discount_paise'] ?? null, $d['net_paise'] ?? null, $d['total_paise'] ?? null], 'preview (Manage → Engage, quote key)');
$r = http('POST', "{$manageUrl}/billing/coupons/preview?cmp_id={$c1}", $sess($H), ['offer_code' => 'books-pro', 'code' => 'NOSUCHCODE']);
eq([false, 'invalid_or_expired'], [$r['json']['data']['valid'] ?? null, $r['json']['data']['reason_code'] ?? null], 'an unknown code: generic reason');
$r = http('POST', "{$manageUrl}/billing/upgrade-requests?cmp_id={$c1}", $sess($H), ['offer_code' => 'books-pro', 'coupon_code' => 'SAVEBIG',
    'contact' => ['name' => 'Asha Owner', 'email' => 'asha@e2e.test', 'phone' => '+919800000000']]);
$d = $r['json']['data'] ?? [];
ok($r['status'] === 200 && is_string($d['request_ref'] ?? null) && ($d['quote']['discount_paise'] ?? null) === 150000, 'upgrade request: ' . ($d['request_ref'] ?? '?') . ' with a reserved quote', $r['body']);
eq(1, (int) $epdo->query("SELECT COUNT(*) FROM engage_coupon_redemptions WHERE state = 'reserved' AND holder_uuid = '{$H}'")->fetchColumn(), 'Engage holds the reservation for staff');
$r = http('POST', "{$manageUrl}/billing/coupons/preview?cmp_id={$c3}", $sess($M), ['offer_code' => 'books-pro', 'code' => 'SAVEBIG']);
eq(404, $r['status'], 'a member (not the owner) cannot use Plans & billing');

// ------------------------------------------------------------------------------------------------ h)
step('h) plans_sync pulls the Basic defaults; reconcile');
[$c, $out] = cli([PHP_BINARY, 'scripts/plans_sync.php'], $manageDir . '/server-php', $manageEnv);
ok($c === 0, 'plans_sync.php (catalogue without cmp_id)');
eq(['billing:billing-basic', 'books:books-basic', 'docs:docs-basic', 'inventory:inventory-basic', 'notes:notes-basic'],
    array_map(static fn (array $r): string => $r['app_key'] . ':' . $r['c'], $mpdo->query("SELECT app_key, plan->>'code' AS c FROM ghm_plan_default ORDER BY app_key")->fetchAll()), 'Manage ghm_plan_default');
cli([PHP_BINARY, 'scripts/plans_sync.php', '--status'], $manageDir . '/server-php', $manageEnv);
$r = http('GET', "{$manageUrl}/v1/me/plan?app=books", $sess($N));
eq(['books-basic', 'default', ['max_companies' => 1, 'max_users' => 2]], [$r['json']['data']['plan']['code'] ?? null, $r['json']['data']['plan']['source'] ?? null, $r['json']['data']['plan']['entitlements'] ?? null],
    'a person Manage has no pushed plan for resolves to the synced Basic (source default)');
$r = http('GET', "{$manageUrl}/v1/me/plan?app=books", $sess($M));
eq(['books-basic', 'licence'], [$r['json']['data']['plan']['code'] ?? null, $r['json']['data']['plan']['source'] ?? null], 'the provisioned member: their pushed Basic (source licence)');
[$c, $out] = cli([PHP_BINARY, 'spark', 'engage:entitlements-reconcile'], $engageDir, $engageEnv);
ok($c === 0 && str_contains($out, ' 0 drift, 0 error(s)'), 'engage:entitlements-reconcile: Engage and Manage agree (read key)', $out);

echo "\n{$passed} passed, {$failed} failed\n";
if (getenv('E2E_TRANSCRIPT')) {
    file_put_contents((string) getenv('E2E_TRANSCRIPT'), implode("\n\n", $log) . "\n");
}
exit($failed > 0 ? 1 : 0);

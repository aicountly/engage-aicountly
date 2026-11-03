<?php
declare(strict_types=1);

/**
 * AI credits, end to end, for EVERY product (docs/TOKEN_CONVERSIONS.md).
 *
 * Included by tests/e2e/run.php once steps a–h have left Engage, Manage and my.aicountly
 * running with a provisioned holder. It adds two more real services to the picture —
 * AI Pulse (its own database, its own CodeIgniter app under php -S) and a Console
 * model catalogue — and then follows one ₹1,000 coupon from a staff screen all the way
 * to tokens being spent:
 *
 *   i) every product Manage knows gets a Basic offer and the holder a Basic licence
 *   j) the rate card: every model Console knows is priced, and pushed to Pulse signed
 *   k) the ₹1,000 coupon in its four shapes (flat / percent, with and without a minimum,
 *      single and multiple use), priced by Engage through Manage's own routes
 *   l) the purchase: order → payment link → Finance's confirmation → order paid, grant queued
 *   m) Engage → Pulse: the grant credited once, then spent product by product
 *   n) the same transaction seen from Engage, from Manage and on the invoice
 *
 * WHAT IS REAL AND WHAT IS NOT. Engage, Manage, my.aicountly and Pulse are the real
 * applications against real PostgreSQL, and every hop between them is a real signed
 * AIC-S2S v1 request over HTTP. Two things stand in:
 *
 *   - Console's model catalogue, which is one read-only GET. The stub serves the shape
 *     Console serves (`{data:{models:[…]}}`) and checks the bearer token.
 *   - Finance, whose half of this — the GST invoice, the gateway, the payment it re-reads
 *     from the provider — is proven by finance-aicountly's own suite (tests/academy-db.php
 *     covers the token-purchase link against a stubbed Razorpay). Here Finance is a stub
 *     that VERIFIES ENGAGE'S SIGNATURE with an independent implementation, asserts the
 *     request Engage sent it, and then posts a real payment event back into Engage's real
 *     inbound route. So what this proves is Engage's side of the Finance contract, not
 *     Finance's own bookkeeping.
 *
 * Skips its own steps (without failing the run) when PULSE_REPO / E2E_PULSE_DIR does not
 * point at a pulse-aicountly checkout with vendor/.
 *
 * @var string $engageUrl @var string $manageUrl @var PDO $epdo @var PDO $mpdo
 * @var callable $as @var callable $sess @var string $maker @var string $checker
 * @var string $H @var int $c1 @var string $engageDir @var array $engageEnv @var array $manageEnv
 * @var string $tmp @var array $procs @var array $dbs @var callable $key @var string $run
 * @var string $pgHost @var int $pgPort @var string $pgUser @var string $pgPass @var PDO $admin
 */

$pulseDir = rtrim((string) (getenv('E2E_PULSE_DIR') ?: getenv('PULSE_REPO') ?: dirname($engageDir, 2) . '/pulse-aicountly'), '/');
if (!is_file($pulseDir . '/server-php/spark') || !is_file($pulseDir . '/server-php/vendor/autoload.php')) {
    echo "\n== i–n) AI credits: SKIPPED — set E2E_PULSE_DIR to a pulse-aicountly checkout with vendor/ (composer install)\n";

    return;
}
$pulsePhp = $pulseDir . '/server-php';

// ------------------------------------------------------------------------------------------------ Pulse + stubs
step('i0) AI Pulse and the Console catalogue join the run');

$dbs['pulse'] = "{$run}_pulse";
$admin->exec("CREATE DATABASE {$dbs['pulse']}");   // dropped by run.php's shutdown handler

$pK = ['id' => 'engage-pulse-e2e', 'secret' => $key()];
$consoleToken = $key();
$financeKey = ['id' => 'engage-finance-e2e', 'secret' => $key()];
$financeInboundKey = $key();                        // Finance → Engage (X-Finance-Key)

$pulseEnv = [
    'CI_ENVIRONMENT' => 'production',
    'database.default.hostname' => $pgHost, 'database.default.port' => (string) $pgPort,
    'database.default.database' => $dbs['pulse'], 'database.default.username' => $pgUser,
    'database.default.password' => $pgPass, 'database.default.DBDriver' => 'Postgre', 'database.default.schema' => 'public',
    // Pulse must not try to fetch its own database details from Console during the run.
    'CONSOLE_API_URL' => '', 'CONSOLE_DB_DETAILS_KEY' => '',
    // A deployed Pulse refuses every API request until it says which deployment it is
    // (EnvironmentGuard: PULSE_ENVIRONMENT), separately from the AIC-S2S environment.
    'PULSE_ENVIRONMENT' => 'sandbox',
    'PULSE_S2S_ENVIRONMENT' => $env,
    'PULSE_INBOUND_ENGAGE_KEY_IDS' => $pK['id'],
    'PULSE_INBOUND_ENGAGE_SECRET_' . $up($pK['id']) => $pK['secret'],
];
[$c, $out] = cli([PHP_BINARY, 'spark', 'migrate'], $pulsePhp, $pulseEnv);
ok($c === 0, 'Pulse migrations', $out);
$ppdo = new PDO("pgsql:host={$pgHost};port={$pgPort};dbname={$dbs['pulse']}", $pgUser, $pgPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
ok((int) $ppdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('buddy_token_wallet','buddy_token_ledger','buddy_token_alerts')")->fetchColumn() === 3,
    'Pulse has the wallet, the ledger and the alert table');

$pP = freePort();
$pulseHost = "http://127.0.0.1:{$pP}";
$pulseUrl = "{$pulseHost}/api";
file_put_contents("{$tmp}/pulse_router.php", '<?php $_SERVER["SCRIPT_NAME"] = "/api/index.php"; $_SERVER["SCRIPT_FILENAME"] = '
    . var_export($pulsePhp . '/index.php', true) . '; require ' . var_export($pulsePhp . '/index.php', true) . ';');
$procs[] = serve($pP, $pulsePhp, "{$tmp}/pulse_router.php", $pulseEnv + ['PHP_CLI_SERVER_WORKERS' => '4'], "{$tmp}/pulse.log");
$h = http('GET', "{$pulseUrl}/health");
// Its scheduled jobs have never run here, so health reads "degraded" — what matters is that
// it is serving and its schema is in place.
eq(['connected', 'ready'], [$h['json']['database'] ?? null, $h['json']['schema'] ?? null], "Pulse up on :{$pP} with its schema");

/**
 * The model catalogue as Console serves it: three providers, four tiers between them, and
 * one model nobody has bound to a module (which must still be listed, as inactive).
 */
$models = [
    ['provider' => ['slug' => 'anthropic', 'name' => 'Anthropic'], 'model_key' => 'claude-opus-5',    'display_name' => 'Claude Opus 5',   'tier' => 'high',   'status' => 'active', 'module_count' => 9],
    ['provider' => ['slug' => 'anthropic', 'name' => 'Anthropic'], 'model_key' => 'claude-sonnet-5',  'display_name' => 'Claude Sonnet 5', 'tier' => 'medium', 'status' => 'active', 'module_count' => 31],
    ['provider' => ['slug' => 'anthropic', 'name' => 'Anthropic'], 'model_key' => 'claude-haiku-5',   'display_name' => 'Claude Haiku 5',  'tier' => 'low',    'status' => 'active', 'module_count' => 12],
    ['provider' => ['slug' => 'openai',    'name' => 'OpenAI'],    'model_key' => 'gpt-5-mini',       'display_name' => 'GPT-5 mini',      'tier' => 'low',    'status' => 'active', 'module_count' => 4],
    ['provider' => ['slug' => 'openai',    'name' => 'OpenAI'],    'model_key' => 'gpt-5',            'display_name' => 'GPT-5',           'tier' => 'high',   'status' => 'active', 'module_count' => 2],
    // In Console but bound to nothing: priced, listed, and not warned about.
    ['provider' => ['slug' => 'google',    'name' => 'Google'],    'model_key' => 'gemini-3-flash',   'display_name' => 'Gemini 3 Flash',  'tier' => null,     'status' => 'active', 'module_count' => 0],
];
file_put_contents("{$tmp}/models.json", json_encode(['data' => ['models' => $models]]));
file_put_contents("{$tmp}/console_stub.php", <<<'PHP'
<?php
// Console, read-only, for the one GET Engage makes: the AI model catalogue.
$want = (string) getenv('STUB_CONSOLE_TOKEN');
$got  = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
header('Content-Type: application/json');
if (!hash_equals($want, $got)) {
    http_response_code(403);
    echo json_encode(['error' => ['code' => 'forbidden', 'message' => 'ai.catalogue capability required']]);
    return;
}
if (!str_ends_with((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), '/portal/ai-models')) {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 'not_found', 'message' => 'no such Console route in this stub']]);
    return;
}
echo (string) file_get_contents((string) getenv('STUB_CONSOLE_MODELS'));
PHP);
$pC = freePort();
$consoleUrl = "http://127.0.0.1:{$pC}";
$procs[] = serve($pC, $tmp, "{$tmp}/console_stub.php",
    ['STUB_CONSOLE_TOKEN' => $consoleToken, 'STUB_CONSOLE_MODELS' => "{$tmp}/models.json"], "{$tmp}/console.log");
$h = http('GET', "{$consoleUrl}/portal/ai-models", ['Authorization' => "Bearer {$consoleToken}"]);
ok($h['status'] === 200 && count($h['json']['data']['models'] ?? []) === 6, 'Console catalogue stub: 6 models', $h['body']);
ok(http('GET', "{$consoleUrl}/portal/ai-models")['status'] === 403, '… and it refuses a caller without the key');

/**
 * Finance, as far as Engage is concerned. It verifies the AIC-S2S v1 signature with its own
 * implementation of the canonical string (not Engage's library), writes the request to a log
 * the test reads, and answers with a payment link.
 */
file_put_contents("{$tmp}/finance_stub.php", <<<'PHP'
<?php
// Finance's inbound Engage route, enough of it to hold Engage to the contract.
header('Content-Type: application/json');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$h = static fn (string $n): string => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $n))] ?? '');
$fail = static function (int $code, string $why) use ($body): void {
    file_put_contents((string) getenv('STUB_FINANCE_LOG'), json_encode(['refused' => $why, 'body' => json_decode($body, true)]) . "\n", FILE_APPEND);
    http_response_code($code);
    echo json_encode(['error' => ['code' => 'signature_invalid', 'message' => $why]]);
};
if ($path !== '/api/v1/inbound/engage/payment-links') {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 'not_found', 'message' => 'not this stub\'s route']]);
    return;
}
// Independent verifier: METHOD \n PATH \n CANONICAL_QUERY \n TIMESTAMP \n NONCE \n sha256(body)
$secret = (string) getenv('STUB_FINANCE_SECRET');
if ($h('X-AIC-Client') !== 'engage' || $h('X-AIC-Key-Id') !== (string) getenv('STUB_FINANCE_KEY_ID')) {
    $fail(401, 'unknown client or key id');
    return;
}
if ($h('X-AIC-Environment') !== (string) getenv('STUB_FINANCE_ENV')) {
    $fail(401, 'wrong environment');
    return;
}
if (abs(time() - (int) $h('X-AIC-Timestamp')) > 300) {
    $fail(401, 'timestamp skew');
    return;
}
$canonical = 'POST' . "\n" . '/v1/inbound/engage/payment-links' . "\n" . '' . "\n" . $h('X-AIC-Timestamp') . "\n" . $h('X-AIC-Nonce') . "\n" . hash('sha256', $body);
if (!hash_equals('v1=' . hash_hmac('sha256', $canonical, $secret), strtolower($h('X-AIC-Signature')))) {
    $fail(401, 'the signature does not match');
    return;
}
$in = json_decode($body, true);
$in = is_array($in) ? $in : [];
file_put_contents((string) getenv('STUB_FINANCE_LOG'), json_encode([
    'idempotency_key' => $h('Idempotency-Key'), 'actor' => $h('X-AIC-Actor'), 'body' => $in,
]) . "\n", FILE_APPEND);
// One link per idempotency key, as Finance's own partial unique index enforces.
$store = (string) getenv('STUB_FINANCE_STATE');
$state = is_file($store) ? (array) json_decode((string) file_get_contents($store), true) : [];
$kkey = $h('Idempotency-Key');
if (!isset($state[$kkey])) {
    $state[$kkey] = ['link_id' => 'plink_' . substr(hash('sha256', $kkey), 0, 12), 'url' => 'https://pay.example.test/l/' . $kkey,
        'expires_at' => gmdate('c', time() + 86400), 'amount_paise' => (int) ($in['amount_paise'] ?? 0)];
    file_put_contents($store, json_encode($state));
}
echo json_encode(['success' => true, 'data' => $state[$kkey], 'request_id' => 'stub']);
PHP);
$pF = freePort();
$financeUrl = "http://127.0.0.1:{$pF}";
$finLog = "{$tmp}/finance-requests.log";
file_put_contents($finLog, '');
$procs[] = serve($pF, $tmp, "{$tmp}/finance_stub.php", [
    'STUB_FINANCE_SECRET' => $financeKey['secret'], 'STUB_FINANCE_KEY_ID' => $financeKey['id'],
    'STUB_FINANCE_ENV' => $env, 'STUB_FINANCE_LOG' => $finLog, 'STUB_FINANCE_STATE' => "{$tmp}/finance-state.json",
], "{$tmp}/finance.log");

/** Engage, now also talking to Console, Pulse and Finance. */
$creditEnv = [
    'CONSOLE_API_URL' => $consoleUrl, 'CONSOLE_API_BASE_URL' => $consoleUrl, 'CONSOLE_API_TOKEN' => $consoleToken,
    'PULSE_API_BASE_URL' => $pulseHost,   // the clients append /api themselves
    'ENGAGE_OUTBOUND_PULSE_KEY_ID' => $pK['id'], 'ENGAGE_OUTBOUND_PULSE_SECRET' => $pK['secret'],
    'FINANCE_API_BASE_URL' => $financeUrl,
    'ENGAGE_OUTBOUND_FINANCE_S2S_KEY_ID' => $financeKey['id'], 'ENGAGE_OUTBOUND_FINANCE_S2S_SECRET' => $financeKey['secret'],
    'ENGAGE_INBOUND_FINANCE_KEY' => $financeInboundKey,
] + $engageEnv;
// The HTTP Engage is already serving does not know about any of that, so start a second one
// with the fuller environment. Both talk to the same database; it is the same Engage.
$pE2 = freePort();
$engageCreditUrl = "http://127.0.0.1:{$pE2}/api";
$procs[] = serve($pE2, $engageDir, "{$tmp}/engage_router.php",
    ['app.baseURL' => "{$engageCreditUrl}/", 'PHP_CLI_SERVER_WORKERS' => '4'] + $creditEnv, "{$tmp}/engage-credits.log");
ok(http('GET', "{$engageCreditUrl}/health")['status'] === 200, "Engage (with Console, Pulse and Finance) up on :{$pE2}");
// Manage must reach THAT one, so its credit routes have a Finance to raise a link with.
$manageCreditEnv = ['ENGAGE_API_BASE_URL' => $engageCreditUrl] + $manageEnv;
$pM2 = freePort();
$manageCreditUrl = "http://127.0.0.1:{$pM2}/api";
$procs[] = serve($pM2, $manageDir . '/server-php/public', $manageDir . '/server-php/tests/support/manage_http_router.php',
    ['PHP_CLI_SERVER_WORKERS' => '4'] + $manageCreditEnv, "{$tmp}/manage-credits.log");
ok(http('GET', "{$manageCreditUrl}/health")['status'] === 200, "Manage (pointed at it) up on :{$pM2}");
$info = http('GET', "{$manageCreditUrl}/billing/info", $sess($H));
eq([true, true], [$info['json']['data']['quotes_enabled'] ?? null, $info['json']['data']['plans_enabled'] ?? null],
    'Manage billing/info: quotes on (the AI credits tab needs it)');

// ------------------------------------------------------------------------------------------------ i)
step('i) a Basic offer for EVERY product Manage knows, and a Basic licence for the holder');

[$c, $out] = cli([PHP_BINARY, 'spark', 'engage:basic-offers', '--apply'], $engageDir, $creditEnv);
ok($c === 0, 'engage:basic-offers --apply (drafts for every product with no default offer)', $out);
$drafts = $epdo->query("SELECT id, offer_code, product_code FROM engage_offers WHERE status = 'draft' AND is_default ORDER BY product_code")->fetchAll();
ok(count($drafts) > 25, 'drafts created for the rest of the estate: ' . count($drafts) . ' product(s)');

// Staff fill in a limit and publish. A Basic with no entitlements at all is still a plan —
// what matters here is that every product HAS one, so one modest limit each is enough.
$published = 0;
$refused = [];
foreach ($drafts as $d) {
    $u = http('PUT', "{$engageUrl}/v1/offers/{$d['id']}", $as($maker),
        ['entitlements' => [['code' => 'max_users', 'label' => 'Users', 'value' => 2, 'unit' => 'count']]]);
    $p = http('POST', "{$engageUrl}/v1/offers/{$d['id']}/publish", $as($maker));
    if ($p['status'] === 200) {
        $published++;
    } else {
        $refused[$d['product_code']] = ($u['status'] !== 200 ? "update {$u['status']}: " : '') . mb_substr($p['body'], 0, 160);
    }
}
ok($refused === [], "every draft published ({$published})", json_encode($refused, JSON_UNESCAPED_SLASHES));
$readiness = http('GET', "{$engageUrl}/v1/offers/basic-readiness", $as($maker));
$missing = array_values(array_filter((array) ($readiness['json']['data']['products'] ?? []),
    static fn (array $p): bool => ($p['has_published_default'] ?? $p['ready'] ?? false) === false));
ok($missing === [], 'basic-readiness: no product is left without a published Basic',
    json_encode(array_column($missing, 'product_code'), JSON_UNESCAPED_SLASHES));

[$c, $out] = cli([PHP_BINARY, 'spark', 'engage:provision-basic', '--holder=' . $H], $engageDir, $creditEnv);
ok($c === 0, 'engage:provision-basic for the holder', $out);
$held = $epdo->query("SELECT product_code FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic AND status IN ('active','superseded') ORDER BY product_code")->fetchAll(PDO::FETCH_COLUMN);
$offered = $epdo->query("SELECT DISTINCT product_code FROM engage_offers WHERE is_default AND status = 'published' ORDER BY product_code")->fetchAll(PDO::FETCH_COLUMN);
eq([], array_values(array_diff($offered, $held)), 'the holder holds a Basic for every product that offers one (' . count($held) . ')');
[$c] = cli([PHP_BINARY, 'spark', 'engage:provision-basic', '--holder=' . $H], $engageDir, $creditEnv);
eq(count($held), (int) $epdo->query("SELECT COUNT(*) FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic")->fetchColumn(), 'a second run changes nothing');

cli([PHP_BINARY, 'spark', 'engage:entitlements-push'], $engageDir, $creditEnv);
[$c] = cli([PHP_BINARY, 'scripts/plans_sync.php'], $manageDir . '/server-php', $manageCreditEnv);
ok($c === 0, 'Manage plans_sync: the whole estate\'s Basic defaults');
// Manage stores a plan per APP KEY, and two products can share one (ourpeople rides on hrms),
// so that is what has to be complete.
$appKeys = $epdo->query("SELECT DISTINCT unnest(app_keys) AS k FROM engage_offers WHERE is_default AND status = 'published' ORDER BY k")->fetchAll(PDO::FETCH_COLUMN);
$stored = $mpdo->query('SELECT app_key FROM ghm_plan_default ORDER BY app_key')->fetchAll(PDO::FETCH_COLUMN);
eq([], array_values(array_diff($appKeys, $stored)), 'Manage holds a Basic default for every app key the estate offers (' . count($stored) . ')');
// The fallback the founder asked to confirm: a person with no pushed plan still resolves to Basic.
$r = http('GET', "{$manageCreditUrl}/v1/me/plan?app=hrms", $sess($N));
eq(['hrms-basic', 'default'], [$r['json']['data']['plan']['code'] ?? null, $r['json']['data']['plan']['source'] ?? null],
    'a product nobody pushed for this person still falls back to its Basic');

// ------------------------------------------------------------------------------------------------ j)
step('j) the rate card: every model priced, pushed to Pulse signed');

$rate = static function (array $body) use ($engageCreditUrl, $as, $maker, $checker): array {
    $r = http('POST', "{$engageCreditUrl}/v1/token-rates", $as($maker), $body);
    $id = (int) ($r['json']['data']['id'] ?? 0);
    if ($id > 0) {
        http('POST', "{$engageCreditUrl}/v1/token-rates/{$id}/activate", $as($checker));
    }

    return [$r, $id];
};
// What sales decides: Sonnet at 10x, Opus at 25x, Haiku at 1x, and OpenAI at 12x for anything.
[$r] = $rate(['provider_code' => 'anthropic', 'model_key' => 'claude-sonnet-5', 'multiplier' => '10', 'label' => 'Sonnet 5 at 10x']);
ok($r['status'] === 201, 'rate: anthropic/claude-sonnet-5 at 10x', $r['body']);
$rate(['provider_code' => 'anthropic', 'model_key' => 'claude-opus-5', 'multiplier' => '25', 'label' => 'Opus 5 at 25x']);
$rate(['provider_code' => 'anthropic', 'model_key' => 'claude-haiku-5', 'multiplier' => '1', 'label' => 'Haiku 5 at 1x']);
$rate(['provider_code' => 'openai', 'multiplier' => '12', 'label' => 'OpenAI, anything, at 12x']);

$cat = http('GET', "{$engageCreditUrl}/v1/token-rates/catalogue", $as($maker));
$byKey = [];
foreach ((array) ($cat['json']['data']['models'] ?? []) as $m) {
    $byKey[$m['provider_slug'] . '|' . $m['model_key']] = $m;
}
eq(6, count($byKey), 'Token Conversions lists every model Console knows');
eq([100000, 'model'], [$byKey['anthropic|claude-sonnet-5']['multiplier_bp'] ?? null, $byKey['anthropic|claude-sonnet-5']['source'] ?? null], 'Sonnet: 10x from the rate somebody set');
eq([120000, 'provider'], [$byKey['openai|gpt-5']['multiplier_bp'] ?? null, $byKey['openai|gpt-5']['source'] ?? null], 'GPT-5: 12x from the provider default');
eq([false, true], [$byKey['google|gemini-3-flash']['in_use'] ?? null, isset($byKey['google|gemini-3-flash'])], 'a model nobody has bound is listed as inactive, not hidden');
// The founder asked for a warning when a model in use has no rate: Gemini has none and is
// unclassified, so it is priced by the fallback — and because nothing runs it, nobody is warned.
eq('unclassified', $byKey['google|gemini-3-flash']['source'] ?? null, '… priced by the unclassified fallback');
ok(str_contains((string) ($byKey['google|gemini-3-flash']['warning'] ?? ''), 'no token rate'), '… and its row says so, for staff to act on');
eq([], (array) ($cat['json']['data']['warnings'] ?? ['missing']), '… but nothing is escalated: no module runs it, so nobody is being charged a guess');

[$c, $out] = cli([PHP_BINARY, 'spark', 'engage:token-rates-push'], $engageDir, $creditEnv);
ok($c === 0 && !str_contains($out, 'unavailable'), 'engage:token-rates-push → Pulse (AIC-S2S v1)', $out);
$card = json_decode((string) $ppdo->query("SELECT value FROM buddy_settings WHERE key = 'gateway_token_rate_card'")->fetchColumn(), true);
eq([100000, 250000, 10000], [$card['rates']['anthropic|claude-sonnet-5'] ?? null, $card['rates']['anthropic|claude-opus-5'] ?? null, $card['rates']['anthropic|claude-haiku-5'] ?? null],
    'Pulse holds the card: Sonnet 10x, Opus 25x, Haiku 1x');
eq(120000, $card['provider_defaults']['openai'] ?? null, '… and OpenAI\'s provider default travels with it');
eq(6, count($card['rates'] ?? []), '… one entry per model, so Pulse never prices from its own fallback for a known model');
$h = http('PUT', "{$pulseUrl}/v1/inbound/engage/token-rate-card", ['X-AIC-Client' => 'engage'], ['rates' => ['anthropic|forged' => 1]]);
eq([401, 'signature_missing'], [$h['status'], $h['json']['code'] ?? null], 'an unsigned push is refused', $h['body']);
ok(!str_contains((string) $ppdo->query("SELECT value FROM buddy_settings WHERE key = 'gateway_token_rate_card'")->fetchColumn(), 'forged'), '… and changed nothing');

// ------------------------------------------------------------------------------------------------ k)
step('k) the ₹1,000 coupon in its four shapes, priced by Engage through Manage');

$coupon = static function (array $body, string $code) use ($engageCreditUrl, $as, $maker, $checker): int {
    $r = http('POST', "{$engageCreditUrl}/v1/coupons", $as($maker), $body + ['code' => $code]);
    $id = (int) ($r['json']['data']['id'] ?? 0);
    ok($r['status'] === 201 && $id > 0, "maker drafts {$code}", $r['body']);
    $a = http('POST', "{$engageCreditUrl}/v1/coupons/{$id}/activate", $as($checker));
    ok($a['status'] === 200, "checker activates {$code} (four eyes)", $a['body']);

    return $id;
};
$unscoped = ['product_codes' => [], 'offer_codes' => [], 'licence_type_codes' => []];
// 1. flat ₹1,000 off, no minimum, usable once per holder
$coupon(['code_kind' => 'shared', 'label' => 'Flat ₹1,000 off credits', 'discount_type' => 'flat', 'flat_paise' => 100000,
    'max_redemptions_per_holder' => 1, 'applies_to' => $unscoped], 'CREDIT1000');
// 2. flat ₹1,000 off, only on ₹2,000 or more, three times per holder
$coupon(['code_kind' => 'shared', 'label' => 'Flat ₹1,000 off over ₹2,000', 'discount_type' => 'flat', 'flat_paise' => 100000,
    'min_order_paise' => 200000, 'max_redemptions_per_holder' => 3, 'applies_to' => $unscoped], 'CREDITMIN2K');
// 3. everything off, capped at ₹1,000 — which on a ₹1,000 purchase leaves nothing to collect
$coupon(['code_kind' => 'shared', 'label' => '100% off up to ₹1,000', 'discount_type' => 'percent', 'percent_bp' => 10000,
    'max_discount_paise' => 100000, 'max_redemptions_per_holder' => 1, 'applies_to' => $unscoped], 'CREDITFREE');
// 4. a fifth off, never more than ₹1,000, as often as they like
$coupon(['code_kind' => 'shared', 'label' => '20% off credits, max ₹1,000', 'discount_type' => 'percent', 'percent_bp' => 2000,
    'max_discount_paise' => 100000, 'applies_to' => $unscoped], 'CREDIT20');

$quote = static fn (array $body) => http('POST', "{$manageCreditUrl}/billing/token-orders/quote?cmp_id={$c1}", $sess($H), $body);

$q = $quote(['amount_rupees' => '1000']);
eq([200, 1000000, 100000, 0, 18000, 118000], [$q['status'], $q['json']['data']['tokens'] ?? null, $q['json']['data']['list_paise'] ?? null,
    $q['json']['data']['discount_paise'] ?? null, $q['json']['data']['gst_paise'] ?? null, $q['json']['data']['total_paise'] ?? null],
    'no coupon: ₹1,000 buys 1,000,000 tokens and collects ₹1,180');

$q = $quote(['amount_rupees' => '5000', 'coupon_code' => 'credit1000']);
eq([400000, 72000, 472000], [$q['json']['data']['net_paise'] ?? null, $q['json']['data']['gst_paise'] ?? null, $q['json']['data']['total_paise'] ?? null],
    '1. flat ₹1,000 off ₹5,000 → ₹4,000 + GST ₹720 = ₹4,720 (GST on the discounted price)');

$q = $quote(['amount_rupees' => '1500', 'coupon_code' => 'CREDITMIN2K']);
eq([422, 'validation_failed'], [$q['status'], $q['json']['code'] ?? null], '2. below the ₹2,000 minimum: refused, not quietly ignored');
ok(str_contains((string) ($q['json']['message'] ?? ''), 'larger purchase'), '… and the customer is told why', $q['body']);
$q = $quote(['amount_rupees' => '2000', 'coupon_code' => 'CREDITMIN2K']);
eq([100000, 18000, 118000], [$q['json']['data']['net_paise'] ?? null, $q['json']['data']['gst_paise'] ?? null, $q['json']['data']['total_paise'] ?? null],
    '… at ₹2,000 it applies: ₹1,000 + GST ₹180 = ₹1,180');

// There is a floor under every discount (PricingService::MIN_NET_PAISE): a coupon can take a
// purchase down to ₹1 and no further, because a ₹0 invoice is meaningless and Finance refuses
// one. A genuinely free grant is a voucher, which is a different instrument altogether.
$q = $quote(['amount_rupees' => '1000', 'coupon_code' => 'CREDITFREE']);
eq([99900, 100, 118], [$q['json']['data']['discount_paise'] ?? null, $q['json']['data']['net_paise'] ?? null, $q['json']['data']['total_paise'] ?? null],
    '3. everything off still leaves ₹1 to invoice — never a ₹0 invoice');
$q = $quote(['tokens' => 10]);
eq([422, 'validation_failed'], [$q['status'], $q['json']['code'] ?? null], '… and a purchase too small to charge for is refused');
ok(str_contains((string) ($q['json']['message'] ?? ''), '₹1.00'), '… saying it must come to at least ₹1.00', $q['body']);

$q = $quote(['amount_rupees' => '10000', 'coupon_code' => 'CREDIT20']);
eq([100000, 900000, 162000, 1062000], [$q['json']['data']['discount_paise'] ?? null, $q['json']['data']['net_paise'] ?? null,
    $q['json']['data']['gst_paise'] ?? null, $q['json']['data']['total_paise'] ?? null],
    '4. 20% of ₹10,000 is ₹2,000, capped at ₹1,000 → ₹9,000 + GST ₹1,620 = ₹10,620');

// The founder's own scenario: ₹1,000 of credits with the coupon that leaves an invoice.
$q = $quote(['amount_rupees' => '1000', 'coupon_code' => 'CREDIT20']);
eq([1000000, 20000, 80000, 14400, 94400], [$q['json']['data']['tokens'] ?? null, $q['json']['data']['discount_paise'] ?? null,
    $q['json']['data']['net_paise'] ?? null, $q['json']['data']['gst_paise'] ?? null, $q['json']['data']['total_paise'] ?? null],
    'the scenario asked for: ₹1,000 of credits less 20% = ₹800 + GST ₹144 = ₹944 collected');
eq(100, $q['json']['data']['price_paise_per_1000'] ?? null, '… at ₹1.00 per 1,000 tokens');

// ------------------------------------------------------------------------------------------------ l)
step('l) the purchase: order → payment link → Finance confirms → paid, grant queued');

$o = http('POST', "{$manageCreditUrl}/billing/token-orders?cmp_id={$c1}", $sess($H), ['amount_rupees' => '1000', 'coupon_code' => 'CREDIT20',
    'payer' => ['name' => 'Asha Owner', 'email' => 'asha@e2e.test', 'state_code' => '27']]);
$order = $o['json']['data'] ?? [];
$orderRef = (string) ($order['order_ref'] ?? '');
eq([200, 'awaiting_payment', true], [$o['status'], $order['status'] ?? null, str_starts_with($orderRef, 'TKO-')],
    "order {$orderRef} created and a payment page handed back");
ok(str_contains((string) ($order['link_url'] ?? ''), 'pay.example.test'), '… the customer is sent to the gateway, not to Manage', $o['body']);

$sent = array_map(static fn (string $l): array => (array) json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($finLog))));
$req = end($sent) ?: [];
ok(($req['idempotency_key'] ?? null) === $orderRef && $orderRef !== '', 'Finance was asked with the order reference as the idempotency key', json_encode($req['idempotency_key'] ?? null));
eq(['tokens', $orderRef, 1000000, 94400], [$req['body']['purpose'] ?? null, $req['body']['source_ref'] ?? null,
    $req['body']['token_count'] ?? null, $req['body']['amount_paise'] ?? null], '… purpose, order, tokens and the amount to collect');
eq([$H, 'exclusive', 100000, 20000, 'CREDIT20', 80000], [$req['body']['buyer_uuid'] ?? null, $req['body']['fee']['price_basis'] ?? null,
    $req['body']['fee']['list_amount_paise'] ?? null, $req['body']['fee']['discount_paise'] ?? null,
    $req['body']['fee']['discount_ref'] ?? null, $req['body']['fee']['taxable_paise'] ?? null],
    '… and what the invoice must print: who it is for, the list price, the discount and its code');
ok(($req['refused'] ?? null) === null, '… Finance verified Engage\'s signature with its own verifier');

// Pressing Pay twice must not make a second link, on either side of the hop.
$again = http('POST', "{$manageCreditUrl}/billing/token-orders?cmp_id={$c1}", $sess($H), ['amount_rupees' => '9999',
    'payer' => ['name' => 'Asha Owner', 'state_code' => '27']]);
eq([$orderRef, true], [$again['json']['data']['order_ref'] ?? null, $again['json']['data']['existing'] ?? null],
    'a second Buy while one is unpaid returns the SAME order, at the price it was quoted at');
eq(1, (int) $epdo->query("SELECT COUNT(*) FROM engage_token_orders WHERE holder_uuid = '{$H}'")->fetchColumn(), '… one order, one link');

// Finance confirms a payment it re-read from the provider. This is the only thing that turns
// money into tokens.
$pay = static fn (array $body) => http('POST', "{$engageCreditUrl}/v1/inbound/finance/payment-events",
    ['X-Finance-Key' => $financeInboundKey], $body);
$r = $pay(['event_type' => 'payment.succeeded', 'source_ref' => $orderRef, 'payment_ref' => 'pay_E2E_' . $run,
    'invoice_no' => 'AIC/26-27/000099', 'invoice_date' => gmdate('Y-m-d')]);
eq([200, 'granted'], [$r['status'], $r['json']['data']['status'] ?? null], 'Finance\'s payment event settles the order', $r['body']);
$row = $epdo->query('SELECT * FROM engage_token_orders WHERE order_ref = ' . $epdo->quote($orderRef))->fetch();
eq(['granted', 'AIC/26-27/000099', 94400, 1000000], [$row['status'], $row['invoice_no'], (int) $row['total_paise'], (int) $row['tokens']],
    '… the order carries the invoice number, the amount collected and the tokens bought');
$grant = $epdo->query('SELECT * FROM engage_token_grants WHERE origin_ref = ' . $epdo->quote($orderRef))->fetch();
eq(['purchase', 1000000, 'pending'], [$grant['origin'], (int) $grant['tokens'], $grant['status']],
    'a grant for exactly what was paid for is queued in the same transaction');
$r = $pay(['event_type' => 'payment.succeeded', 'source_ref' => $orderRef, 'payment_ref' => 'pay_E2E_' . $run]);
eq([200, true], [$r['status'], $r['json']['data']['duplicate'] ?? null], 'Finance retrying is a success, not a second grant');
eq(1, (int) $epdo->query('SELECT COUNT(*) FROM engage_token_grants WHERE origin_ref = ' . $epdo->quote($orderRef))->fetchColumn(), '… still one grant');
$forged = http('POST', "{$engageCreditUrl}/v1/inbound/finance/payment-events", [], ['event_type' => 'payment.succeeded',
    'source_ref' => $orderRef, 'payment_ref' => 'pay_FORGED']);
ok($forged['status'] >= 400, 'a payment event without Finance\'s key is refused (HTTP ' . $forged['status'] . ')', $forged['body']);
eq(0, (int) $epdo->query("SELECT COUNT(*) FROM engage_token_orders WHERE payment_ref = 'pay_FORGED'")->fetchColumn(), '… and granted nothing');

// ------------------------------------------------------------------------------------------------ m)
step('m) Engage → Pulse: credited once, then spent product by product');

[$c, $out] = cli([PHP_BINARY, 'spark', 'engage:token-grants-push'], $engageDir, $creditEnv);
ok($c === 0, 'engage:token-grants-push → Pulse (AIC-S2S v1)', $out);
eq('delivered', $epdo->query('SELECT status FROM engage_token_grants WHERE origin_ref = ' . $epdo->quote($orderRef))->fetchColumn(), 'the grant is marked delivered');
$w = $ppdo->query("SELECT * FROM buddy_token_wallet WHERE holder_uuid = '{$H}'")->fetch();
eq(1000000, (int) ($w['purchased_tokens'] ?? 0), 'Pulse: 1,000,000 bought tokens in the wallet');
cli([PHP_BINARY, 'spark', 'engage:token-grants-push'], $engageDir, $creditEnv);
eq([1000000, 1], [(int) $ppdo->query("SELECT purchased_tokens FROM buddy_token_wallet WHERE holder_uuid = '{$H}'")->fetchColumn(),
    (int) $ppdo->query("SELECT COUNT(*) FROM buddy_token_ledger WHERE grant_ref = " . $ppdo->quote((string) $grant['grant_ref']))->fetchColumn()],
    'a retried push credits nothing twice (the grant reference is the key)');

// Spend it the way the estate does: through Pulse's own wallet and rate card, against the
// card Engage pushed, once for every product that holds a Basic licence. One balance serves
// them all — that is the point of a wallet rather than a per-product allowance.
require_once $pulsePhp . '/app/Services/Ai/TokenRateCard.php';
require_once $pulsePhp . '/app/Services/Ai/GatewayError.php';
require_once $pulsePhp . '/app/Services/Ai/TokenWallet.php';
require_once $pulsePhp . '/app/Support/BudgetDay.php';
require_once $pulsePhp . '/tests/support/PdoDbShim.php';
$shim = new \Tests\Support\PdoDbShim($ppdo);
$liveCard = \App\Services\Ai\TokenRateCard::fromSetting($card);
$spin = [['anthropic', 'claude-haiku-5', 10000], ['anthropic', 'claude-sonnet-5', 100000], ['anthropic', 'claude-opus-5', 250000], ['openai', 'gpt-5', 120000]];
// What Pulse calls the four ways a rate can be found: a model rate, a provider default, a
// Console tier, or the estate fallback.
$products = $epdo->query("SELECT product_code FROM engage_licences WHERE holder_uuid = '{$H}' AND is_basic ORDER BY product_code")->fetchAll(PDO::FETCH_COLUMN);
$spentTotal = 0;
$wrongRate = [];
$before = (int) $ppdo->query("SELECT purchased_tokens FROM buddy_token_wallet WHERE holder_uuid = '{$H}'")->fetchColumn();
foreach ($products as $i => $product) {
    [$provider, $model, $wantBp] = $spin[$i % count($spin)];
    $r = $liveCard->resolve($provider, $model);
    if ($r['multiplier_bp'] !== $wantBp) {
        $wrongRate[$product] = "{$provider}/{$model} resolved {$r['multiplier_bp']}, expected {$wantBp}";
    }
    $billable = $liveCard->billable(100, 20, $r['multiplier_bp']);     // 120 raw tokens a call
    $d = \App\Services\Ai\TokenWallet::debit($shim, $H, $billable, [
        'provider' => $provider, 'model' => $model, 'raw_tokens' => 120,
        'multiplier_bp' => $r['multiplier_bp'], 'rate_source' => $r['source'],
        'reason' => "E2E: an AI call made for {$product}",
    ], ['code' => "{$product}-basic", 'entitlements' => [], 'holder_uuid' => $H]);
    $spentTotal += $d['purchased'];
}
ok($wrongRate === [], 'every product\'s call is priced by the card Engage pushed', json_encode($wrongRate, JSON_UNESCAPED_SLASHES));
ok(count($products) > 25, count($products) . ' products each made a charged AI call against the one balance');
$after = (int) $ppdo->query("SELECT purchased_tokens FROM buddy_token_wallet WHERE holder_uuid = '{$H}'")->fetchColumn();
eq($before - $spentTotal, $after, 'the balance fell by exactly what was charged (' . number_format($spentTotal) . ' tokens)');
$net = (int) $ppdo->query("SELECT COALESCE(SUM(tokens), 0) FROM buddy_token_ledger WHERE holder_uuid = '{$H}'")->fetchColumn();
eq($after, $net, 'the ledger nets to the balance — every movement is explained');
$sources = $ppdo->query("SELECT DISTINCT rate_source FROM buddy_token_ledger WHERE holder_uuid = '{$H}' AND entry_type = 'debit' ORDER BY rate_source")->fetchAll(PDO::FETCH_COLUMN);
// Pulse records `card` because the card it was given is already resolved — one multiplier
// per model, worked out by Engage, which is the whole point: two implementations of pricing
// agree until the day they do not. What made the number is in Engage's rate history; what
// Pulse must keep is the multiplier it actually applied, and it does.
eq(['card'], $sources, 'and each charge records HOW it was priced, so a past charge stays explicable');
$one = $ppdo->query("SELECT multiplier_bp, raw_tokens, tokens FROM buddy_token_ledger WHERE holder_uuid = '{$H}' AND model = 'claude-opus-5' LIMIT 1")->fetch();
eq([250000, 120, -3000], [(int) $one['multiplier_bp'], (int) $one['raw_tokens'], (int) $one['tokens']], '… 120 raw tokens on Opus at 25x is 3,000 charged');

// ------------------------------------------------------------------------------------------------ n)
step('n) one transaction, seen from Engage, from Manage and on the invoice');

$hist = http('GET', "{$manageCreditUrl}/billing/token-orders?cmp_id={$c1}", $sess($H));
$mine = (array) ($hist['json']['data']['items'] ?? []);
$shown = array_values(array_filter($mine, static fn (array $x): bool => ($x['order_ref'] ?? '') === $orderRef))[0] ?? [];
eq([200, 'granted', 1000000, 94400, 'AIC/26-27/000099'], [$hist['status'], $shown['status'] ?? null, (int) ($shown['tokens'] ?? 0),
    (int) ($shown['total_paise'] ?? 0), $shown['invoice_no'] ?? null], 'Manage shows the customer their purchase, credited, with its invoice number');
eq(404, http('GET', "{$manageCreditUrl}/billing/token-orders?cmp_id={$c1}", $sess($M))['status'], 'a member of the company cannot see it (the licence holder only)');

$red = $epdo->query('SELECT state, net_paise, discount_paise, order_ref, licence_ref FROM engage_coupon_redemptions WHERE holder_uuid = ' . $epdo->quote($H)
    . " AND code = 'CREDIT20' ORDER BY id DESC LIMIT 1")->fetch();
ok($red !== false, 'Engage recorded the coupon redemption against the holder', json_encode($red));
if ($red !== false) {
    eq(['committed', 80000, 20000], [$red['state'], (int) $red['net_paise'], (int) $red['discount_paise']], '… committed, at the discount the customer was actually given');
    eq([$orderRef, null], [$red['order_ref'], $red['licence_ref']], '… against the order, not a licence');
}
$once = $quote(['amount_rupees' => '5000', 'coupon_code' => 'CREDIT1000']);
ok($once['status'] === 200, 'a coupon that has not been used is still offered', $once['body']);
// CREDIT20 has no per-holder limit, so it stays usable; the single-use one would not.
$again = $quote(['amount_rupees' => '1000', 'coupon_code' => 'CREDIT20']);
ok($again['status'] === 200, 'a coupon with no limit can be used again', $again['body']);
$code = $epdo->query("SELECT status FROM engage_coupon_codes WHERE code = 'CREDIT20'")->fetchColumn();
eq('available', $code, '… and its code is still available');

echo "\n       the ₹1,000 credit, end to end:\n";
printf("         bought      %s tokens for %s (list %s less %s with CREDIT20, GST %s)\n", number_format(1000000),
    '₹944.00', '₹1,000.00', '₹200.00', '₹144.00');
printf("         invoice     %s   order %s   payment pay_E2E_%s\n", 'AIC/26-27/000099', $orderRef, $run);
printf("         spent       %s tokens across %d products\n", number_format($spentTotal), count($products));
printf("         left        %s tokens, and the ledger agrees\n", number_format($after));

<?php
declare(strict_types=1);

/**
 * Engage's daily snapshot for analytics.aicountly.org — who pays, and what they hold.
 *
 *   php server-php/scripts/analytics_snapshot.php                      # work it out, print it
 *   php server-php/scripts/analytics_snapshot.php --push               # and send it
 *   php server-php/scripts/analytics_snapshot.php --date=2026-11-01 --push   # backfill one day
 *
 * ## Why this exists
 *
 * Analytics sees behaviour. Commercial state is not behaviour: whether a company pays is a fact
 * about a contract, not about a click, and no quantity of events will ever reveal it. Engage is
 * the only system that knows, so Engage reports it (analytics docs/08).
 *
 * Engage is registered with Analytics as `infrastructure`, not as a product, which keeps these
 * figures out of every product-tier adoption number. It sends snapshots and no events.
 *
 * ## Nobody is in here
 *
 * Counts and sums of paise. No holder, no company, no licence reference travels — only how many
 * and how much.
 *
 * ## Money is paise, always
 *
 * Analytics stores money as an integer number of paise and divides by 100 once, at the point of
 * display. Engage already keeps *_paise columns, so nothing is converted here: a figure that
 * passes through a float on its way to a dashboard acquires a rounding error nobody can find.
 *
 * ## What is NOT reported, and why
 *
 * revenue.mrr_paise is deliberately absent. Monthly recurring revenue needs each licence's price
 * AND its billing period, and `engage_licences` carries the term's end but not a periodised
 * price, so any MRR computed here would be a guess presented as a measurement. A wrong revenue
 * figure on an owner's dashboard is worse than a blank one, and the Estate page shows "no
 * collector has reported this" rather than a zero. Add it here when Engage can state it exactly.
 *
 * Cron (once a day, after midnight IST — a different minute from the other collectors):
 *   31 1 * * *  cd /home/<acct>/public_html && php server-php/scripts/analytics_snapshot.php --push \
 *                 >> ~/logs/engage-analytics-snapshot.log 2>&1
 */

require_once __DIR__ . '/../app/ThirdParty/AicountlyAnalytics/analytics-platform-snapshot.php';

const SOURCE = 'engage';

function scalar(PDO $pdo, string $sql, array $args = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($args);

    return (int) $st->fetchColumn();
}

function hasTable(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT count(*) FROM information_schema.tables
                          WHERE table_schema = current_schema() AND table_name = :t');
    $st->execute(['t' => $table]);

    return (int) $st->fetchColumn() > 0;
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT count(*) FROM information_schema.columns
                          WHERE table_schema = current_schema() AND table_name = :t AND column_name = :c');
    $st->execute(['t' => $table, 'c' => $column]);

    return (int) $st->fetchColumn() > 0;
}

// ── arguments ───────────────────────────────────────────────────────────────
$o    = getopt('', ['push', 'date::', 'env::', 'db::']);
$push = isset($o['push']);
$asOf = is_string($o['date'] ?? null) && $o['date'] !== '' ? $o['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(2);
}
$envPath = is_string($o['env'] ?? null) && $o['env'] !== '' ? $o['env'] : dirname(__DIR__) . '/.env';
$env     = aic_analytics_read_env($envPath);
$get     = static fn (string $k, string $d = ''): string => $env[$k] ?? (string) (getenv($k) ?: $d);

$prefix = is_string($o['db'] ?? null) && $o['db'] !== '' ? $o['db'] : 'DB';
try {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
        $get($prefix . '_HOST', '127.0.0.1'), $get($prefix . '_PORT', '5432'), $get($prefix . '_NAME'));
    if ($get($prefix . '_SSLMODE') !== '') {
        $dsn .= ';sslmode=' . $get($prefix . '_SSLMODE');
    }
    $db = new PDO($dsn, $get($prefix . '_USER'), $get($prefix . '_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'cannot reach the Engage database: ' . $e->getMessage() . "\n");
    exit(1);
}

$snapshots = [];
$notes     = [];
$add = static function (string $metric, int $value, string $dimension = '') use (&$snapshots, $asOf): void {
    $snapshots[] = ['metric' => $metric, 'as_of_date' => $asOf, 'value' => max(0, $value)]
        + ($dimension !== '' ? ['dimension' => $dimension] : []);
};

// ── Who holds a licence, and whether they pay for it ─────────────────────────
if (!hasTable($db, 'engage_licences')) {
    fwrite(STDERR, "engage_licences is not in this database; nothing commercial to report.\n");
    exit(1);
}

// `is_basic` is Engage's free tier. A holder with any non-basic active licence is a paying
// customer; one whose active licences are all basic is on free. The two are counted as
// DISTINCT HOLDERS and the paid test is applied first, so a holder with both is counted once,
// as paying — which is what they are.
$paidWhere = hasColumn($db, 'engage_licences', 'is_basic')
    ? "status = 'active' AND is_basic = FALSE"
    : "status = 'active'";

$paid = scalar($db, "SELECT count(DISTINCT holder_uuid) FROM engage_licences WHERE {$paidWhere}");
$add('customers.paid', $paid);

if (hasColumn($db, 'engage_licences', 'is_basic')) {
    $add('customers.free', scalar($db,
        "SELECT count(DISTINCT holder_uuid) FROM engage_licences l
          WHERE l.status = 'active' AND l.is_basic = TRUE
            AND NOT EXISTS (SELECT 1 FROM engage_licences p
                             WHERE p.holder_uuid = l.holder_uuid
                               AND p.status = 'active' AND p.is_basic = FALSE)"));
} else {
    $notes[] = 'engage_licences has no is_basic column — paid and free cannot be told apart';
}

// Paying holders, broken down by what they bought. The dimension is the product code, which is
// the breakdown docs/08 names for this metric.
if (hasColumn($db, 'engage_licences', 'product_code')) {
    $st = $db->query("SELECT product_code, count(DISTINCT holder_uuid) AS n
                        FROM engage_licences WHERE {$paidWhere}
                    GROUP BY product_code ORDER BY product_code");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dim = aic_analytics_dimension((string) $row['product_code']);
        if ($dim !== '') {
            $add('customers.paid', (int) $row['n'], $dim);
        }
    }
}

// Engage records 'active', 'pending' and 'expired'; there is no trial state, so customers
// .trialing is not sent rather than sent as a zero. Zero and "not measured" are different
// answers and the Estate page shows them differently.
$notes[] = 'engage_licences has no trial state — customers.trialing is not reported';

// ── Money actually collected on the day ──────────────────────────────────────
if (hasTable($db, 'engage_lead_payments') && hasColumn($db, 'engage_lead_payments', 'net_paise')) {
    $dateCol = hasColumn($db, 'engage_lead_payments', 'paid_at') ? 'paid_at'
        : (hasColumn($db, 'engage_lead_payments', 'created_at') ? 'created_at' : null);
    if ($dateCol === null) {
        $notes[] = 'engage_lead_payments has no date column — revenue.collected_paise is not reported';
    } else {
        $add('revenue.collected_paise', scalar($db,
            "SELECT COALESCE(SUM(net_paise), 0) FROM engage_lead_payments WHERE {$dateCol}::date = :d",
            ['d' => $asOf]));
    }
} else {
    $notes[] = 'engage_lead_payments / net_paise absent — revenue.collected_paise is not reported';
}

// ── AI tokens customers hold and have not spent ──────────────────────────────
if (hasTable($db, 'engage_token_grants') && hasColumn($db, 'engage_token_grants', 'tokens_left')) {
    // A revoked or forfeited grant is not a balance anybody holds.
    $conds = ['tokens_left > 0'];
    foreach (['tokens_revoked_at' => 'IS NULL', 'tokens_forfeited' => '= FALSE'] as $col => $test) {
        if (hasColumn($db, 'engage_token_grants', $col)) {
            $conds[] = "{$col} {$test}";
        }
    }
    $add('tokens.balance', scalar($db,
        'SELECT COALESCE(SUM(tokens_left), 0) FROM engage_token_grants WHERE ' . implode(' AND ', $conds)));
} else {
    $notes[] = 'engage_token_grants / tokens_left absent — tokens.balance is not reported';
}

// ── report, or push ─────────────────────────────────────────────────────────
printf("%s  %s  (%d counters)\n", $asOf, SOURCE, count($snapshots));
foreach ($snapshots as $s) {
    printf("  %-26s %-14s %s\n", $s['metric'], $s['dimension'] ?? '-', number_format($s['value']));
}
foreach ($notes as $note) {
    printf("  note: %s\n", $note);
}

if (!$push) {
    echo "\nDry run. Pass --push to send.\n";
    exit(0);
}

$key = $get('AICOUNTLY_ANALYTICS_KEY');
if ($key === '') {
    fwrite(STDERR, "AICOUNTLY_ANALYTICS_KEY is not set in {$envPath}; nothing sent.\n");
    exit(1);
}
[$code, $body] = aic_analytics_platform_push(
    $snapshots,
    $get('AICOUNTLY_ANALYTICS_URL', 'https://analytics.aicountly.org/api'),
    $key,
    $get('AICOUNTLY_ANALYTICS_ENVIRONMENT', 'production')
);

echo "\nHTTP {$code}\n" . substr($body, 0, 800) . "\n";
exit($code >= 200 && $code < 300 ? 0 : 1);

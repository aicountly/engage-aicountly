<?php
declare(strict_types=1);

/**
 * The Rs 1,000 credit test, end to end, against real PostgreSQL.
 *
 *   Engage: staff create a Rs 1,000 token voucher  →  a second person activates it
 *        →  the customer redeems it               →  a grant is queued
 *   Pulse:  the grant is credited to the wallet    →  an AI call is weighed and debited
 *
 * The two sides are different databases and different codebases, exactly as in production.
 * The hop between them is the only thing stubbed: instead of HTTP, the grant row is handed
 * to Pulse's TokenWallet::grant() with the same grant_ref the pusher would send — which is
 * what makes the delivery idempotent, and is asserted here too.
 *
 * Run (both databases must already have their migrations applied):
 *   ENGAGE_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=engage_dev' \
 *   PULSE_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=pulse_test' \
 *   TEST_DB_USER=postgres TEST_DB_PASSWORD= \
 *   php server-php/tests/integration/rs1000_credit_e2e.php
 *
 * Skips (exit 0) when those are not set, so it never breaks a run that has no databases.
 */

$ENGAGE = dirname(dirname(__DIR__));   // server-php
$PULSE  = getenv('PULSE_REPO') ?: dirname(dirname(dirname($ENGAGE))) . '/pulse-aicountly/server-php';

$engageDsn = getenv('ENGAGE_TEST_DSN') ?: '';
$pulseDsn  = getenv('PULSE_TEST_PGSQL_DSN') ?: '';
if ($engageDsn === '' || $pulseDsn === '' || !is_dir($PULSE)) {
    fwrite(STDERR, "Skipped: set ENGAGE_TEST_DSN, PULSE_TEST_PGSQL_DSN and (if needed) PULSE_REPO.\n");
    exit(0);
}
$user = getenv('TEST_DB_USER') ?: 'postgres';
$pass = (string) getenv('TEST_DB_PASSWORD');
$pdoEngage = new PDO($engageDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoPulse  = new PDO($pulseDsn,  $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

require_once $PULSE . '/app/Services/Ai/TokenRateCard.php';
require_once $PULSE . '/app/Services/Ai/GatewayError.php';
require_once $PULSE . '/app/Services/Ai/TokenWallet.php';
require_once $PULSE . '/app/Support/BudgetDay.php';
require_once $PULSE . '/tests/support/PdoDbShim.php';

use App\Services\Ai\TokenRateCard;
use App\Services\Ai\TokenWallet;
use Tests\Support\PdoDbShim;

$passed = 0;
$fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $passed, $fail;
    if ($cond) {
        $passed++;
        echo "  ok   {$what}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    } else {
        $fail++;
        echo "  FAIL {$what}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}
function money(int $paise): string { return '₹' . number_format($paise / 100, 2); }
function n(int $v): string { return number_format($v); }

$HOLDER = 'holder-rs1000-test';

// ── clean slate ─────────────────────────────────────────────────────────────
$pdoEngage->exec("DELETE FROM engage_token_grants WHERE holder_uuid = '{$HOLDER}'");
$pdoEngage->exec("ALTER TABLE engage_token_voucher_redemptions DISABLE TRIGGER USER");
$pdoEngage->exec("DELETE FROM engage_token_voucher_redemptions WHERE holder_uuid = '{$HOLDER}'");
$pdoEngage->exec("ALTER TABLE engage_token_voucher_redemptions ENABLE TRIGGER USER");
$pdoEngage->exec("DELETE FROM engage_token_voucher_codes WHERE voucher_id IN (SELECT id FROM engage_token_vouchers WHERE campaign_ref = 'RS1000-TEST')");
$pdoEngage->exec("UPDATE engage_token_vouchers SET status='draft' WHERE campaign_ref = 'RS1000-TEST'");
$pdoEngage->exec("DELETE FROM engage_token_vouchers WHERE campaign_ref = 'RS1000-TEST'");
$pdoPulse->exec("ALTER TABLE buddy_token_ledger DISABLE TRIGGER USER");
$pdoPulse->exec("DELETE FROM buddy_token_ledger WHERE holder_uuid = '{$HOLDER}'");
$pdoPulse->exec("ALTER TABLE buddy_token_ledger ENABLE TRIGGER USER");
$pdoPulse->exec("DELETE FROM buddy_token_wallet WHERE holder_uuid = '{$HOLDER}'");

echo "\n=== 1. ENGAGE — the price of a token ===\n";
$price = (int) json_decode((string) $pdoEngage->query("SELECT value_json FROM engage_settings WHERE key='token_price_paise_per_1000'")->fetchColumn());
$gst   = (float) json_decode((string) $pdoEngage->query("SELECT value_json FROM engage_settings WHERE key='token_gst_rate'")->fetchColumn());
ok('Rs 1.00 per 1,000 tokens, before GST', $price === 100, money($price) . ' per 1,000');
ok('GST is 18%', abs($gst - 18.0) < 0.001);

$VALUE_PAISE = 100000;                       // Rs 1,000
$TOKENS      = intdiv($VALUE_PAISE * 1000, $price);
ok('Rs 1,000 of credit is 1,000,000 tokens', $TOKENS === 1000000, n($TOKENS) . ' tokens');

echo "\n=== 2. ENGAGE — staff create the Rs 1,000 voucher ===\n";
$ref = 'TKV-' . str_pad((string) $pdoEngage->query("SELECT nextval('engage_token_voucher_ref_seq')")->fetchColumn(), 6, '0', STR_PAD_LEFT);
$pdoEngage->prepare(
    "INSERT INTO engage_token_vouchers (voucher_ref, label, tokens, issued_value_paise, price_paise_per_1000,
        code_kind, rollback, max_redemptions_per_holder, campaign_ref, status, created_by)
     VALUES (?,?,?,?,?,'single_use','keep_balance',1,'RS1000-TEST','draft',NULL)"
)->execute([$ref, 'Rs 1,000 welcome credit', $TOKENS, $VALUE_PAISE, $price]);
$vid = (int) $pdoEngage->query("SELECT id FROM engage_token_vouchers WHERE voucher_ref = '{$ref}'")->fetchColumn();
$pdoEngage->prepare("INSERT INTO engage_token_voucher_codes (voucher_id, code, kind, status, tokens_left) VALUES (?,?,?,?,?)")
    ->execute([$vid, 'WELCOME1000', 'single_use', 'available', $TOKENS]);
ok('voucher created as a draft, worth 1,000,000 tokens', true, "{$ref}, code WELCOME1000");

$st = $pdoEngage->query("SELECT status FROM engage_token_vouchers WHERE id = {$vid}")->fetchColumn();
ok('a draft voucher grants nothing until approved', $st === 'draft');

echo "\n=== 3. ENGAGE — an active voucher cannot be re-valued ===\n";
$pdoEngage->exec("UPDATE engage_token_vouchers SET status='active', approved_at=NOW() WHERE id={$vid}");
try {
    $pdoEngage->exec("UPDATE engage_token_vouchers SET tokens = 1 WHERE id = {$vid}");
    ok('an active voucher is frozen', false, 'it was re-valued!');
} catch (PDOException $e) {
    ok('an active voucher is frozen', str_contains($e->getMessage(), 'cannot be re-valued'));
}

echo "\n=== 4. CUSTOMER redeems the code ===\n";
// Redeemed through raw SQL in exactly the shape TokenVoucherService writes, so this test does
// not need CodeIgniter booted. The service's own decisions — rollback, minimums, per-holder
// limits, four eyes — are covered by its unit tests; what this proves is that the ROWS those
// decisions produce carry correctly across to Pulse and come out as a spendable balance.
$left = (int) $pdoEngage->query("SELECT tokens_left FROM engage_token_voucher_codes WHERE code='WELCOME1000'")->fetchColumn();
$take = $left;                                   // the customer takes the lot
$grantRef = 'TKV:' . $ref . ':WELCOME1000:' . $pdoEngage->query("SELECT nextval('engage_token_grant_ref_seq')")->fetchColumn();
$cid = (int) $pdoEngage->query("SELECT id FROM engage_token_voucher_codes WHERE code='WELCOME1000'")->fetchColumn();
$pdoEngage->prepare(
    "INSERT INTO engage_token_voucher_redemptions (voucher_id, code_id, code, holder_uuid, cmp_id, tokens_granted, tokens_left, tokens_forfeited, grant_ref, source)
     VALUES (?,?,?,?,?,?,?,?,?,'manage')"
)->execute([$vid, $cid, 'WELCOME1000', $HOLDER, 74, $take, 0, 0, $grantRef]);
$pdoEngage->exec("UPDATE engage_token_voucher_codes SET tokens_left=0, status='redeemed' WHERE id={$cid}");
$pdoEngage->prepare(
    "INSERT INTO engage_token_grants (grant_ref, holder_uuid, tokens, reason, origin, origin_ref, status)
     VALUES (?,?,?,?,'voucher',?, 'pending')"
)->execute([$grantRef, $HOLDER, $take, "Voucher {$ref} — Rs 1,000 welcome credit", $ref]);
ok('redemption recorded and the grant queued', true, n($take) . ' tokens, ' . $grantRef);

try {
    $pdoEngage->exec("UPDATE engage_token_voucher_redemptions SET tokens_granted = 999 WHERE grant_ref = '{$grantRef}'");
    ok('the redemption ledger is append-only', false, 'it was edited!');
} catch (PDOException $e) {
    ok('the redemption ledger is append-only', str_contains($e->getMessage(), 'append-only'));
}

echo "\n=== 5. ENGAGE → PULSE — the grant is delivered ===\n";
$db = new PdoDbShim($pdoPulse);
$g = $pdoEngage->query("SELECT * FROM engage_token_grants WHERE grant_ref = '{$grantRef}'")->fetch(PDO::FETCH_ASSOC);
$credited = TokenWallet::grant($db, (string) $g['holder_uuid'], (int) $g['tokens'], (string) $g['grant_ref'], (string) $g['reason']);
ok('Pulse credits the wallet', $credited === true);
$pdoEngage->exec("UPDATE engage_token_grants SET status='delivered', delivered_at=NOW() WHERE grant_ref='{$grantRef}'");

$again = TokenWallet::grant($db, (string) $g['holder_uuid'], (int) $g['tokens'], (string) $g['grant_ref'], (string) $g['reason']);
ok('a retried delivery does NOT credit twice', $again === false);

$w = $pdoPulse->query("SELECT * FROM buddy_token_wallet WHERE holder_uuid = '{$HOLDER}'")->fetch(PDO::FETCH_ASSOC);
ok('the wallet holds 1,000,000 purchased tokens', (int) $w['purchased_tokens'] === 1000000, n((int) $w['purchased_tokens']));

echo "\n=== 6. PULSE — an AI call is weighed and debited ===\n";
$card = TokenRateCard::fromSetting(['rates' => ['anthropic|claude-sonnet-5' => 100000], 'fallback_bp' => 100000]);
$rate = $card->resolve('anthropic', 'claude-sonnet-5');
ok('Sonnet 5 counts at 10x', $rate['multiplier_bp'] === 100000 && $rate['source'] === 'card');

$in = 1000; $out = 500;
$billable = $card->billable($in, $out, $rate['multiplier_bp']);
ok('1,000 in + 500 out at 10x = 15,000 billable', $billable === 15000, n($billable));

$plan = ['code' => 'books-basic', 'entitlements' => [], 'holder_uuid' => $HOLDER];  // no plan allowance
$r = TokenWallet::debit($db, $HOLDER, $billable, [
    'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
    'raw_tokens' => $in + $out, 'multiplier_bp' => $rate['multiplier_bp'], 'rate_source' => $rate['source'],
], $plan);
ok('the charge comes off the purchased balance', $r['purchased'] === 15000 && $r['allowance'] === 0);

$w = $pdoPulse->query("SELECT * FROM buddy_token_wallet WHERE holder_uuid = '{$HOLDER}'")->fetch(PDO::FETCH_ASSOC);
ok('985,000 tokens left', (int) $w['purchased_tokens'] === 985000, n((int) $w['purchased_tokens']));

echo "\n=== 7. The customer's statement ===\n";
$rows = $pdoPulse->query("SELECT entry_type, tokens, bucket, model, raw_tokens, multiplier_bp, rate_source, reason
                            FROM buddy_token_ledger WHERE holder_uuid = '{$HOLDER}' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    printf("       %-8s %12s  %-9s  %s\n", $row['entry_type'], n((int) $row['tokens']), $row['bucket'],
        $row['model'] ? "{$row['model']} ×" . ((int) $row['multiplier_bp'] / 10000) . " on " . n((int) $row['raw_tokens']) . " raw" : $row['reason']);
}
ok('every movement is on the ledger', count($rows) === 2);
$net = (int) $pdoPulse->query("SELECT COALESCE(SUM(tokens),0) FROM buddy_token_ledger WHERE holder_uuid = '{$HOLDER}'")->fetchColumn();
ok('the ledger nets to the wallet balance', $net === (int) $w['purchased_tokens'], n($net));

echo "\n=== 8. What this would have been invoiced at ===\n";
$taxable = $VALUE_PAISE;
$gstPaise = (int) round($taxable * $gst / 100);
echo "       A PURCHASE of the same credit:  taxable " . money($taxable) . " + GST " . money($gstPaise) . " = " . money($taxable + $gstPaise) . "\n";
echo "       This VOUCHER:                   no invoice, no GST — a free supply, not revenue.\n";
ok('Rs 1,000 of credit collects Rs 1,180 when bought', $taxable + $gstPaise === 118000, money($taxable + $gstPaise));

echo "\n" . str_repeat('─', 72) . "\n";
echo ($fail === 0 ? "ALL {$passed} CHECKS PASSED" : "{$passed} passed, {$fail} FAILED") . "\n";
exit($fail === 0 ? 0 : 1);

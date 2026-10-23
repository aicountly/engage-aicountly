<?php

namespace Tests\Feature;

use App\Services\Coupons\CouponException;
use App\Services\Coupons\CouponLicenceHook;
use App\Services\Coupons\RedemptionService;
use Config\Database;
use Tests\Support\CouponTestCase;

/**
 * Quotes, reservations, limits (with row locks, also under real concurrency), the redemption state machine, expiry of
 * stale reservations and the licence hook (commit in the licence's transaction). docs/COUPONS.md §4–6.
 */
final class CouponRedemptionTest extends CouponTestCase
{
    private const HOLDER = '6f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37';
    private const HOLDER2 = '0b2f9d1e-1111-4e8a-9c61-5d2e8f0a1b38';

    public function testQuoteWithoutCouponAndWithCouponOnBothBases(): void
    {
        $this->offers();
        $plain = $this->quote(['holder_uuid' => self::HOLDER, 'offer_code' => 'books-annual']);
        $this->assertSame(201, $this->code($plain), (string) $plain->response()->getBody());
        $q = $this->json($plain)['data'];
        $this->assertMatchesRegularExpression('/\AQTE-\d{6}\z/', $q['quote_ref']);
        $this->assertSame([1180000, 0, 1180000, 1000000, 180000, 1180000], [$q['list_paise'], $q['discount_paise'], $q['net_paise'], $q['taxable_paise'], $q['gst_paise'], $q['total_paise']]);
        $this->assertNull($q['coupon']);
        $this->assertNull($q['redemption']);
        $this->assertNull($q['finance_line']['discount_paise']);

        $c = $this->activeCoupon(['percent_bp' => 2000]);
        $inc = $this->json($this->quote(['holder_uuid' => self::HOLDER, 'offer_code' => 'books-annual', 'coupon_code' => 'Diwali 26']))['data'];
        $this->assertSame([1180000, 236000, 944000, 800000, 144000, 944000], [$inc['list_paise'], $inc['discount_paise'], $inc['net_paise'], $inc['taxable_paise'], $inc['gst_paise'], $inc['total_paise']]);
        $this->assertSame('inclusive', $inc['price_basis']);
        $this->assertSame('18', $inc['gst_rate']);
        $this->assertSame('reserved', $inc['redemption']['state']);
        $this->assertSame(['amount_paise' => 944000, 'list_amount_paise' => 1180000, 'discount_paise' => 236000, 'discount_ref' => 'DIWALI26',
            'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331'], $inc['finance_line']);
        $days = (strtotime($inc['expires_at']) - time()) / 86400;
        $this->assertEqualsWithDelta(14, $days, 0.01, 'reserved for 14 days');

        $exc = $this->json($this->quote(['cmp_id' => 42, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26']))['data'];
        $this->assertSame([1000000, 200000, 800000, 800000, 144000, 944000], [$exc['list_paise'], $exc['discount_paise'], $exc['net_paise'], $exc['taxable_paise'], $exc['gst_paise'], $exc['total_paise']]);

        $r = $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $inc['quote_ref'])->get()->getRowArray();
        $this->assertSame('h:' . self::HOLDER, $r['holder_key']);
        $this->assertSame('quote:' . $inc['quote_ref'], $r['idempotency_key']);
        $this->assertSame(2, (int) $this->json($this->asStaff($this->maker(), 'get', "v1/coupons/{$c['id']}"))['data']['stats']['reserved']);

        // Idempotency key on the quote: the same request again answers the first quote.
        $k = ['cmp_id' => 7, 'offer_code' => 'books-pro', 'idempotency_key' => 'crm-req-000001'];
        $first = $this->json($this->quote($k))['data'];
        $again = $this->quote($k);
        $this->assertSame(200, $this->code($again));
        $this->assertSame($first['quote_ref'], $this->json($again)['data']['quote_ref']);
    }

    public function testRejectionsGiveStaffTheDetailedReason(): void
    {
        $this->offers();
        $this->activeCoupon(['applies_to' => ['product_codes' => ['books'], 'offer_codes' => ['books-pro']], 'min_order_paise' => 500000, 'new_or_renewal' => 'new']);
        $cases = [
            [['coupon_code' => 'NOPE1234'], 'unknown_code', 'invalid_or_expired'],
            [['offer_code' => 'books-annual'], 'wrong_offer', 'not_applicable'],
            [['kind' => 'renewal'], 'wrong_kind', 'not_applicable'],
        ];
        foreach ($cases as [$over, $reason, $generic]) {
            $r = $this->quote(array_merge(['cmp_id' => 42, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26'], $over));
            $this->assertSame(422, $this->code($r), $reason);
            $this->assertSame($reason, $this->json($r)['details']['reason']);
            $this->assertSame($generic, $this->json($r)['details']['reason_code']);
        }
        $this->assertSame(0, $this->db()->table('engage_quotes')->countAllResults(), 'a refused quote leaves nothing behind');
        $this->assertSame(3, $this->db()->table('engage_coupon_attempts')->where('outcome', 'rejected')->countAllResults());
        $this->assertSame(0, $this->db()->table('engage_coupon_attempts')->like('code_sha256', 'DIWALI')->countAllResults(), 'never the raw code');
        $bad = $this->quote(['offer_code' => 'nope']);
        $this->assertSame(422, $this->code($bad));
        $this->assertArrayHasKey('offer_code', $this->json($bad)['details']);
        $this->assertArrayHasKey('holder_uuid', $this->json($bad)['details']);
    }

    public function testTotalAndPerHolderLimitsCountLiveUsesOnly(): void
    {
        $this->offers();
        $c = $this->activeCoupon(['max_redemptions_total' => 3, 'max_redemptions_per_holder' => 1]);
        $ok1 = $this->json($this->quote(['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26']))['data'];
        $again = $this->quote(['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26']);
        $this->assertSame('holder_limit', $this->json($again)['details']['reason']);
        $this->assertSame('limit_reached', $this->json($again)['details']['reason_code']);
        $this->assertSame(201, $this->code($this->quote(['holder_uuid' => self::HOLDER2, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26'])));
        $this->assertSame(201, $this->code($this->quote(['cmp_id' => 99, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26'])));
        $full = $this->quote(['cmp_id' => 100, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26']);
        $this->assertSame('total_limit', $this->json($full)['details']['reason']);

        // Releasing a reservation frees its use (for the holder and the total).
        $rid = (int) $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $ok1['quote_ref'])->get()->getRow()->id;
        $this->assertSame(422, $this->code($this->asStaff($this->maker(), 'post', "v1/coupon-redemptions/{$rid}/release", ['reason' => ''])));
        $rel = $this->asStaff($this->maker(), 'post', "v1/coupon-redemptions/{$rid}/release", ['reason' => 'Customer chose another plan']);
        $this->assertSame(200, $this->code($rel));
        $this->assertSame('released', $this->json($rel)['data']['state']);
        $this->assertSame('expired', $this->db()->table('engage_quotes')->where('quote_ref', $ok1['quote_ref'])->get()->getRow()->status);
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'post', "v1/coupon-redemptions/{$rid}/release", ['reason' => 'again'])));
        $this->assertSame(201, $this->code($this->quote(['holder_uuid' => self::HOLDER, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26'])));

        // A lapsed reservation no longer counts, and the job releases it.
        $this->db()->query("ALTER TABLE engage_coupon_redemptions DISABLE TRIGGER trg_engage_coupon_redemptions_guard");
        $this->db()->query("UPDATE engage_coupon_redemptions SET reserved_until = NOW() - INTERVAL '1 minute' WHERE state = 'reserved' AND cmp_id = 99");
        $this->db()->query("ALTER TABLE engage_coupon_redemptions ENABLE TRIGGER trg_engage_coupon_redemptions_guard");
        $this->assertSame(201, $this->code($this->quote(['cmp_id' => 101, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26'])));
        $res = (new RedemptionService())->expireStale();
        $this->assertSame(1, $res['released']);
        $this->assertSame('expired', $this->db()->table('engage_coupon_redemptions')->where('cmp_id', 99)->get()->getRow()->release_reason);
        $this->assertSame(3, (int) $this->json($this->asStaff($this->maker(), 'get', "v1/coupons/{$c['id']}"))['data']['stats']['reserved']);
    }

    public function testSingleUseCodeIsHeldOnceAndReturnsWhenReleased(): void
    {
        $this->offers();
        $c = $this->activeCoupon(['code_kind' => 'single_use', 'code' => null, 'discount_type' => 'flat', 'flat_paise' => 50000, 'percent_bp' => null], 2);
        [$code1] = $this->codesOf($c['id']);
        $q1 = $this->json($this->quote(['cmp_id' => 1, 'offer_code' => 'books-annual', 'coupon_code' => strtolower($code1)]))['data'];
        $this->assertSame(50000, $q1['discount_paise']);
        $this->assertSame(172373, $q1['gst_paise'], 'worked example 3: ₹500 off ₹11,800 incl.');
        $this->assertSame('reserved', $this->db()->table('engage_coupon_codes')->where('code', $code1)->get()->getRow()->status);
        $used = $this->quote(['cmp_id' => 2, 'offer_code' => 'books-annual', 'coupon_code' => $code1]);
        $this->assertSame('code_used', $this->json($used)['details']['reason']);
        // The DB refuses a second live redemption of a single-use code even if the service were bypassed.
        $r = $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $q1['quote_ref'])->get()->getRowArray();
        try {
            $this->db()->query("INSERT INTO engage_coupon_redemptions (coupon_id, code_id, code, single_use, cmp_id, holder_key, offer_code, offer_version,
                product_code, price_basis, gst_rate, list_paise, discount_paise, net_paise, taxable_paise, gst_paise, total_paise, reserved_until, idempotency_key)
                SELECT coupon_id, code_id, code, TRUE, 3, 'c:3', offer_code, offer_version, product_code, price_basis, gst_rate, list_paise, discount_paise,
                net_paise, taxable_paise, gst_paise, total_paise, reserved_until, 'dup-test' FROM engage_coupon_redemptions WHERE id = ?", [$r['id']]);
            $this->fail('partial unique index must refuse');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('uq_engage_coupon_redemptions_single_use', $e->getMessage());
        }
        // Lapsed: the next quote takes the code over at once (the stale reservation is released in the same transaction).
        $this->db()->query("ALTER TABLE engage_coupon_redemptions DISABLE TRIGGER trg_engage_coupon_redemptions_guard");
        $this->db()->query("UPDATE engage_coupon_redemptions SET reserved_until = NOW() - INTERVAL '1 second' WHERE id = ?", [$r['id']]);
        $this->db()->query("ALTER TABLE engage_coupon_redemptions ENABLE TRIGGER trg_engage_coupon_redemptions_guard");
        $this->assertSame(201, $this->code($this->quote(['cmp_id' => 2, 'offer_code' => 'books-annual', 'coupon_code' => $code1])));
        $this->assertSame('released', $this->db()->table('engage_coupon_redemptions')->where('id', $r['id'])->get()->getRow()->state);
    }

    public function testRedemptionStateMachineIsEnforcedByTheDatabase(): void
    {
        $this->offers();
        $this->activeCoupon();
        $q = $this->json($this->quote(['cmp_id' => 5, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26']))['data'];
        $id = (int) $this->db()->table('engage_coupon_redemptions')->where('quote_ref', $q['quote_ref'])->get()->getRow()->id;
        $refuse = function (string $sql, string $needle) use ($id) {
            try {
                $this->db()->query($sql, [$id]);
                $this->fail('must refuse: ' . $sql);
            } catch (\Throwable $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        };
        $refuse("UPDATE engage_coupon_redemptions SET state = 'reversed', reversed_at = NOW(), reversal_reason = 'x', committed_at = NOW(), licence_ref = 'LIC-1' WHERE id = ?", 'cannot move from reserved to reversed');
        $refuse('UPDATE engage_coupon_redemptions SET discount_paise = 1 WHERE id = ?', 'immutable');
        $refuse('DELETE FROM engage_coupon_redemptions WHERE id = ?', 'never deleted');
        $refuse("UPDATE engage_coupon_redemptions SET state = 'committed' WHERE id = ?", 'engage_coupon_redemptions_commit_chk');

        $this->db()->query("UPDATE engage_coupon_redemptions SET state = 'committed', committed_at = NOW(), licence_ref = 'LIC-000009' WHERE id = ?", [$id]);
        $refuse("UPDATE engage_coupon_redemptions SET state = 'released', released_at = NOW() WHERE id = ?", 'cannot move from committed to released');
        $refuse("UPDATE engage_coupon_redemptions SET licence_ref = 'LIC-000010' WHERE id = ?", 'keeps its licence');
        $this->assertSame(409, $this->code($this->asStaff($this->maker(), 'post', "v1/coupon-redemptions/{$id}/release", ['reason' => 'nope'])));
        $this->assertSame(422, $this->code($this->asStaff($this->maker(), 'post', "v1/coupon-redemptions/{$id}/reverse", ['reason' => ''])));
        $rev = $this->asStaff($this->maker(), 'post', "v1/coupon-redemptions/{$id}/reverse", ['reason' => 'Invoice credited in full']);
        $this->assertSame(200, $this->code($rev));
        $this->assertSame('reversed', $this->json($rev)['data']['state']);
        $refuse("UPDATE engage_coupon_redemptions SET state = 'committed' WHERE id = ?", 'cannot move from reversed');
    }

    public function testHookCommitsInTheLicenceTransactionAndIsIdempotent(): void
    {
        $this->offers();
        $c = $this->activeCoupon(['max_redemptions_total' => 1]);
        $q = $this->json($this->quote(['holder_uuid' => self::HOLDER, 'cmp_id' => 42, 'offer_code' => 'books-pro', 'coupon_code' => 'DIWALI26']))['data'];
        $db = Database::connect();
        $licence = ['licence_ref' => 'LIC-000777', 'offer_code' => 'books-pro', 'holder_uuid' => self::HOLDER, 'cmp_id' => 42];

        $this->assertNull(CouponLicenceHook::preflight($db, 'issued', $licence, ['quote_ref' => $q['quote_ref']]));
        $this->assertNull(CouponLicenceHook::afterLicenceWrite($db, 'issued', $licence, []), 'no quote_ref → nothing');
        $pf = CouponLicenceHook::preflight($db, 'issued', ['offer_code' => 'books-annual'] + $licence, ['quote_ref' => $q['quote_ref']]);
        $this->assertSame('quote_mismatch', $pf['details']['reason']);
        $this->assertSame('quote_mismatch', CouponLicenceHook::preflight($db, 'renewed', $licence, ['quote_ref' => $q['quote_ref']])['details']['reason']);
        $this->assertSame('quote_mismatch', CouponLicenceHook::preflight($db, 'issued', ['holder_uuid' => self::HOLDER2] + $licence, ['quote_ref' => $q['quote_ref']])['details']['reason']);
        $this->assertSame('quote_not_found', CouponLicenceHook::preflight($db, 'issued', $licence, ['quote_ref' => 'QTE-999999'])['details']['reason']);

        // The licence transaction rolls back → the redemption stays reserved.
        $db->transBegin();
        CouponLicenceHook::afterLicenceWrite($db, 'issued', $licence, ['quote_ref' => $q['quote_ref'], 'invoice_refs' => ['AIC/26-27/000123']]);
        $this->assertSame('committed', $db->query('SELECT state FROM engage_coupon_redemptions WHERE quote_ref = ?', [$q['quote_ref']])->getRow()->state);
        $db->transRollback();
        $this->assertSame('reserved', $db->query('SELECT state FROM engage_coupon_redemptions WHERE quote_ref = ?', [$q['quote_ref']])->getRow()->state);

        // Committed with the licence.
        $db->transBegin();
        $out = CouponLicenceHook::afterLicenceWrite($db, 'issued', $licence, ['quote_ref' => strtolower($q['quote_ref']), 'invoice_refs' => 'AIC/26-27/000123']);
        $db->transCommit();
        $this->assertSame(200000, $out['discount_paise']);
        $r = $db->query('SELECT * FROM engage_coupon_redemptions WHERE quote_ref = ?', [$q['quote_ref']])->getRowArray();
        $this->assertSame('committed', $r['state']);
        $this->assertSame('LIC-000777', $r['licence_ref']);
        $this->assertSame('AIC/26-27/000123', $r['finance_document_ref']);
        $quote = $db->query('SELECT * FROM engage_quotes WHERE quote_ref = ?', [$q['quote_ref']])->getRowArray();
        $this->assertSame('used', $quote['status']);
        $this->assertSame('LIC-000777', $quote['licence_ref']);
        $this->assertSame('exhausted', $db->query('SELECT status FROM engage_coupons WHERE id = ?', [$c['id']])->getRow()->status, 'total limit used → exhausted');

        // Same licence again: no-op. Another licence: refused.
        $this->assertNotNull(CouponLicenceHook::afterLicenceWrite($db, 'issued', $licence, ['quote_ref' => $q['quote_ref']]));
        try {
            CouponLicenceHook::afterLicenceWrite($db, 'issued', ['licence_ref' => 'LIC-000778'] + $licence, ['quote_ref' => $q['quote_ref']]);
            $this->fail('a used quote cannot be used for another licence');
        } catch (CouponException $e) {
            $this->assertSame('quote_used', $e->reason);
            $this->assertSame(422, $e->status);
        }
        $rep = $this->json($this->asStaff($this->maker(), 'get', 'v1/coupon-report'))['data'];
        $this->assertSame(1, $rep['totals']['committed']);
        $this->assertSame([1000000, 200000, 800000], [$rep['totals']['list_paise'], $rep['totals']['discount_paise'], $rep['totals']['net_paise']]);
        $this->assertSame('CPN-000001', $rep['by_coupon'][0]['coupon_ref']);
        $this->assertContains('coupon.redeemed', array_column($this->db()->table('engage_audit_logs')->get()->getResultArray(), 'event'));

        // Expired quote / quote without a coupon.
        $plain = $this->json($this->quote(['cmp_id' => 43, 'offer_code' => 'books-pro']))['data'];
        $db->query("UPDATE engage_quotes SET expires_at = NOW() - INTERVAL '1 second' WHERE quote_ref = ?", [$plain['quote_ref']]);
        $this->assertSame('quote_expired', CouponLicenceHook::preflight($db, 'issued', ['offer_code' => 'books-pro', 'cmp_id' => 43], ['quote_ref' => $plain['quote_ref']])['details']['reason']);
        $plain2 = $this->json($this->quote(['cmp_id' => 44, 'offer_code' => 'books-pro', 'kind' => 'renewal']))['data'];
        $this->assertNull(CouponLicenceHook::afterLicenceWrite($db, 'renewed', ['licence_ref' => 'LIC-000800', 'offer_code' => 'books-pro', 'cmp_id' => 44], ['quote_ref' => $plain2['quote_ref']])['redemption_id']);
    }

    /** A connection of its own (pg_connect reuses an open link for an identical connection string). */
    private function separateConnection(string $name)
    {
        $cfg = config('Database')->tests;
        $cfg['DSN'] = sprintf("host=%s port=%s user=%s password='%s' dbname=%s application_name=%s",
            $cfg['hostname'], $cfg['port'], $cfg['username'], $cfg['password'], $cfg['database'], $name);

        return Database::connect($cfg, false);
    }

    public function testRowLocksSerialiseTheLastUse(): void
    {
        $this->offers();
        $this->activeCoupon(['max_redemptions_total' => 1]);
        $offer = (new \App\Services\Licensing\OfferService())->publishedVersion('books-pro');
        $args = static fn (int $cmp, string $ref) => ['code' => 'DIWALI26', 'offer' => $offer, 'kind' => 'new', 'holder_uuid' => null, 'cmp_id' => $cmp,
            'lead_id' => null, 'quote_ref' => $ref, 'idempotency_key' => 'lock-test:' . $ref, 'reserved_until' => gmdate('Y-m-d H:i:s', time() + 3600) . '+00',
            'source' => 'staff', 'staff_id' => null];

        $a = $this->separateConnection('lock_a');
        $b = $this->separateConnection('lock_b');
        $a->transBegin();
        (new RedemptionService($a))->reserve($args(1, 'QTE-900001'));   // holds the code + coupon locks
        $b->query("SET lock_timeout = '300ms'");
        $b->transBegin();
        $blocked = null;
        try {
            (new RedemptionService($b))->reserve($args(2, 'QTE-900002'));
        } catch (\Throwable $e) {
            $blocked = $e;
            $dbError = (string) ($b->error()['message'] ?? '');
        } finally {
            $b->query('ROLLBACK');
            $b->transRollback();
            $a->transCommit();
        }
        $this->assertNotNull($blocked, 'B must wait for A’s lock');
        $this->assertNotInstanceOf(CouponException::class, $blocked, $blocked->getMessage());
        $this->assertMatchesRegularExpression('/lock.?timeout/i', $blocked->getMessage() . ' ' . $dbError);
        $b->transBegin();
        try {
            (new RedemptionService($b))->reserve($args(2, 'QTE-900002'));
            $this->fail('the only use is taken');
        } catch (CouponException $e) {
            $this->assertSame('total_limit', $e->reason);
        }
        $b->transRollback();
        $a->close();
        $b->close();
    }

    public function testConcurrentReservationsNeverExceedTheLimit(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl / posix not available');
        }
        $this->offers();
        $this->activeCoupon(['max_redemptions_total' => 3]);
        $offer = (new \App\Services\Licensing\OfferService())->publishedVersion('books-pro');
        $this->db()->close();
        $dir = sys_get_temp_dir() . '/engage-coupon-race-' . getmypid();
        @mkdir($dir);
        $pids = [];
        for ($i = 1; $i <= 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $out = 'error';
                try {
                    $db = $this->separateConnection('race_' . $i);
                    $db->transBegin();
                    try {
                        (new RedemptionService($db))->reserve(['code' => 'DIWALI26', 'offer' => $offer, 'kind' => 'new', 'holder_uuid' => null, 'cmp_id' => 1000 + $i,
                            'lead_id' => null, 'quote_ref' => 'QTE-80000' . $i, 'idempotency_key' => 'race:' . $i,
                            'reserved_until' => gmdate('Y-m-d H:i:s', time() + 3600) . '+00', 'source' => 'staff', 'staff_id' => null]);
                        usleep(20000);
                        $db->transCommit();
                        $out = 'ok';
                    } catch (CouponException $e) {
                        $db->transRollback();
                        $out = $e->reason;
                    }
                } catch (\Throwable $e) {
                    $out = 'error:' . $e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $out);
                posix_kill(getmypid(), SIGKILL); // never run the parent's shutdown handlers in the child
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $results = [];
        for ($i = 1; $i <= 8; $i++) {
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);
        $this->db()->reconnect();
        $counts = array_count_values($results);
        $this->assertSame(3, $counts['ok'] ?? 0, json_encode($results));
        $this->assertSame(5, $counts['total_limit'] ?? 0, json_encode($results));
        $this->assertSame(3, $this->db()->table('engage_coupon_redemptions')->where('state', 'reserved')->countAllResults());
    }
}

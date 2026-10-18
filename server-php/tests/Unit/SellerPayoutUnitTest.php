<?php

namespace Tests\Unit;

use App\Commands\SellerPayoutRun;
use App\Libraries\Seller\PayoutWeek;
use App\Services\Payouts\SellerPayoutService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/** Payout weeks (IST, ISO-8601), refs, the run command's options, seller-facing status and the HTML statement. */
final class SellerPayoutUnitTest extends CIUnitTestCase
{
    private static function ist(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Asia/Kolkata')))->getTimestamp();
    }

    public function testWeekParsingAndRefs(): void
    {
        $w = PayoutWeek::parse('2026-W42');
        $this->assertSame('2026-W42', $w->code());
        $this->assertSame('2026-10-12', $w->periodStart());
        $this->assertSame('2026-10-18', $w->periodEnd());
        $this->assertSame('PB-2026-42', $w->batchRef());
        $this->assertSame('PO-2026-42-000123', $w->payoutRef('SLR-000123'));
        $this->assertSame(self::ist('2026-10-19 00:00:00'), $w->endsAt());
        $this->assertSame('2026-10-19T10:00:00+05:30', $w->scheduledRunAt()->format(DATE_ATOM));
        $this->assertSame('2026-W43', $w->next()->code());
        $this->assertSame('2026-W42', PayoutWeek::parse('2026w42')->code());
        // ISO year differs from the calendar year around New Year.
        $this->assertSame('2026-W01', PayoutWeek::containing(self::ist('2025-12-31 12:00:00'))->code());
        $this->assertSame('2025-12-29', PayoutWeek::parse('2026-W01')->periodStart());
        $this->assertSame('2026-W53', PayoutWeek::parse('2026-W53')->code());
        foreach (['2026-W54', '2025-W53', '2026-42', 'W42', '2026-W00'] as $bad) {
            try {
                PayoutWeek::parse($bad);
                $this->fail("accepted {$bad}");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testWeekBoundariesAreIst(): void
    {
        // Sunday 23:59 IST belongs to the week; Monday 00:00 IST starts the next one (Sunday 18:30 UTC).
        $this->assertSame('2026-W42', PayoutWeek::containing(self::ist('2026-10-18 23:59:59'))->code());
        $this->assertSame('2026-W43', PayoutWeek::containing(self::ist('2026-10-19 00:00:00'))->code());
        $monday10 = self::ist('2026-10-19 10:00:00');
        $this->assertSame('2026-W42', PayoutWeek::lastCompleted($monday10)->code());
        $this->assertSame('2026-W43', PayoutWeek::current($monday10)->code());
        $this->assertTrue(PayoutWeek::parse('2026-W42')->hasEnded($monday10));
        $this->assertFalse(PayoutWeek::parse('2026-W43')->hasEnded($monday10));
        $this->assertSame('2026-10-19', PayoutWeek::nextRunDate(self::ist('2026-10-18 12:00:00')));
        $this->assertSame('2026-10-19', PayoutWeek::nextRunDate(self::ist('2026-10-19 09:59:00')));
        $this->assertSame('2026-10-26', PayoutWeek::nextRunDate(self::ist('2026-10-19 10:00:00')));
        $this->assertSame('2026-10-26', PayoutWeek::nextRunDate(self::ist('2026-10-21 08:00:00')));
    }

    public function testCommandOptions(): void
    {
        $this->assertSame([null, false], SellerPayoutRun::options([], ['spark', 'engage:seller-payout-run']));
        $this->assertSame(['2026-W42', true], SellerPayoutRun::options(['week=2026-W42' => null, 'dry-run' => null], ['spark', 'engage:seller-payout-run', '--week=2026-W42', '--dry-run']));
        $this->assertSame(['2026-W41', false], SellerPayoutRun::options(['week' => '2026-W41'], ['spark', 'engage:seller-payout-run', '--week', '2026-W41']));
        $this->assertSame([null, true], SellerPayoutRun::options(['dry-run' => null], []));
    }

    public function testSellerStatusMapping(): void
    {
        $this->assertSame('processing', SellerPayoutService::sellerStatus('queued'));
        $this->assertSame('processing', SellerPayoutService::sellerStatus('draft_sent'));
        foreach (['approved', 'on_hold', 'paid', 'failed', 'reversed', 'cancelled'] as $s) {
            $this->assertSame($s, SellerPayoutService::sellerStatus($s));
        }
        $item = SellerPayoutService::sellerItem(['payout_ref' => 'PO-2026-42-000001', 'batch_ref' => 'PB-2026-42', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18',
            'gross_amount' => '100.5', 'tds_amount' => null, 'gst_amount' => null, 'net_amount' => null, 'utr' => 'SHOULD-NOT-SHOW', 'status' => 'approved',
            'paid_at' => null, 'reason' => null, 'entries_count' => 2]);
        $this->assertSame('100.50', $item['gross_amount']);
        $this->assertNull($item['utr'], 'a UTR is only shown once paid');
        $this->assertNull($item['net_amount']);
    }

    public function testStatementEscapesAndShowsPendingAmounts(): void
    {
        $html = SellerPayoutService::statementHtml([
            'payout_ref' => 'PO-2026-42-000001', 'batch_ref' => 'PB-2026-42', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18',
            'gross_amount' => '1200.00', 'tds_amount' => null, 'gst_amount' => null, 'net_amount' => null, 'utr' => null, 'status' => 'processing',
            'paid_at' => null, 'reason' => '<img src=x onerror=alert(1)>', 'entries_count' => 1, 'tds_section' => '194H',
            'entries' => [['id' => 'CE-1', 'lead_code' => 'LD-1', 'product' => '<b>Books</b>', 'amount' => '1200.00', 'accrued_at' => '2026-10-01T00:00:00Z']],
            'payee' => ['legal_name' => 'R&D "Associates"', 'seller_code' => 'SLR-000001', 'entity_type' => 'partnership', 'pan_masked' => 'XXXXXX234F', 'gstin_masked' => null],
            'issuer' => null, 'generated_at' => '2026-10-19T05:00:00Z',
        ]);
        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Books&lt;/b&gt;', $html);
        $this->assertStringContainsString('R&amp;D &quot;Associates&quot;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame(3, substr_count($html, 'class="r">Pending<'), 'TDS, GST and net are never estimated');
        $this->assertStringContainsString('12 Oct 2026 – 18 Oct 2026', $html);
        $this->assertStringContainsString('Processing (draft with Finance)', $html);
    }
}

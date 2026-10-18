<?php

namespace Tests\Unit;

use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Licensing\OfferService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Licensing helpers: Indian-calendar dates and terms, cursor (contract §0.3), text[] literals, entitlement validation.
 *
 * @internal
 */
final class LicensingSupportTest extends CIUnitTestCase
{
    public function testDatesAreIndianCalendarDays(): void
    {
        $this->assertSame('2026-10-17 18:30:00+00', S::parseInstant('2026-10-18'));
        $this->assertSame('2026-10-18 05:30:00+00', S::parseInstant('2026-10-18T11:00:00+05:30'));
        $this->assertSame('2026-10-18 11:00:00+00', S::parseInstant('2026-10-18T11:00:00Z'));
        foreach (['', '18/10/2026', '2026-02-30', 'tomorrow', '2026-10-18T11:00:00', null, 5] as $bad) {
            $this->assertNull(S::parseInstant($bad), var_export($bad, true));
        }
        $this->assertSame('18/10/2026', S::displayDate('2026-10-17 18:30:00+00'));
        $this->assertSame('2026-10-17T18:30:00Z', S::iso('2026-10-17 18:30:00+00'));
    }

    public function testTerms(): void
    {
        $start = S::parseInstant('2026-10-18');
        $this->assertSame(S::parseInstant('2027-10-18'), S::addTerm($start, 12, 0));
        $this->assertSame(S::parseInstant('2026-11-17'), S::addTerm($start, 0, 30));
        $this->assertSame(S::parseInstant('2026-12-03'), S::addTerm($start, 1, 15));
        $this->assertNull(S::addTerm($start, 0, 0));
        $this->assertSame('12 months', S::cycleLabel(12, 0));
        $this->assertSame('1 month 1 day', S::cycleLabel(1, 1));
        $this->assertNull(S::cycleLabel(0, 0));
    }

    public function testCursorRoundTripAndRejection(): void
    {
        $c = S::encodeCursor('2026-10-18T05:30:00.123456Z', 42);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_\-]+\z/', $c);
        $this->assertSame(['d' => '2026-10-18T05:30:00.123456Z', 'id' => 42], S::decodeCursor($c));
        $this->assertSame(['d' => '2026-10-18T05:30:00.123456Z', 'id' => 42], json_decode(base64_decode(strtr($c, '-_', '+/')), true));
        foreach (['', 'abc', S::encodeCursor('yesterday', 1), S::encodeCursor('2026-10-18T05:30:00.123456Z', 0), str_repeat('a', 300), 'a b'] as $bad) {
            $this->assertNull(S::decodeCursor($bad), $bad);
        }
    }

    public function testPgArrayLiteral(): void
    {
        $this->assertSame('{}', S::pgArray([]));
        $this->assertSame('{"books","a\"b","c\\\\d"}', S::pgArray(['books', 'a"b', 'c\\d']));
        $this->assertSame(['a', 'b'], S::fromJsonArray('["a","b"]'));
        $this->assertSame([], S::fromJsonArray(null));
    }

    public function testEntitlementsAreValidatedNotInvented(): void
    {
        [$ok, $err] = OfferService::normaliseEntitlements([['code' => 'Users', 'label' => 'Users', 'value' => 5, 'unit' => 'seats'], ['code' => 'gst', 'label' => 'GST returns', 'value' => true]]);
        $this->assertNull($err);
        $this->assertSame([['code' => 'users', 'label' => 'Users', 'value' => 5, 'unit' => 'seats'], ['code' => 'gst', 'label' => 'GST returns', 'value' => true, 'unit' => null]], $ok);
        $this->assertSame([[], null], OfferService::normaliseEntitlements([]));
        foreach ([[['label' => 'x']], [['code' => 'a', 'label' => '']], [['code' => 'a', 'label' => 'A', 'value' => ['x']]], ['k' => ['code' => 'a', 'label' => 'A']], 'nope'] as $bad) {
            $this->assertNotNull(OfferService::normaliseEntitlements($bad)[1]);
        }
        $this->assertSame('18', OfferService::rate('18.00'));
        $this->assertSame('12.5', OfferService::rate('12.50'));
        $this->assertSame('0', OfferService::rate('0.00'));
    }
}

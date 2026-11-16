<?php

namespace Tests\Unit;

use App\Libraries\Email\AicountlyEmailConfig;
use App\Services\Email\DeliveryWebhook;
use App\Services\Email\EmailDeliveryLedger;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Delivery-event webhook from the AICOUNTLY Email API: signature
 * sha256=hex(HMAC_SHA256(secret, timestamp + "." + raw_body)), the 5-minute window, refusals before any database
 * work, and the event → status mapping the ledger applies (ordering-safe merge). The database half is
 * tests/Feature/EmailDeliveryWebhookTest.
 */
final class AicountlyEmailWebhookTest extends CIUnitTestCase
{
    private const SECRET = 'whsec_test_0123456789abcdef0123456789';
    private const NOW    = 1_800_000_000;

    private static function sign(string $body, int|string $ts, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    private static function hook(string $secret = self::SECRET): DeliveryWebhook
    {
        return new DeliveryWebhook(
            new AicountlyEmailConfig(static fn (string $k): string => $k === 'AICOUNTLY_EMAIL_WEBHOOK_SECRET' ? $secret : ''),
            null,
            static fn (): int => self::NOW,
        );
    }

    public function testValidSignatureVerifies(): void
    {
        $body = '{"event_id":991,"event":"bounced"}';
        $this->assertNull(DeliveryWebhook::verify(self::SECRET, (string) self::NOW, self::sign($body, self::NOW), $body, self::NOW));
        // Upper-case hex and a timestamp just inside the window are fine.
        $this->assertNull(DeliveryWebhook::verify(self::SECRET, (string) (self::NOW - 300), strtoupper(self::sign($body, self::NOW - 300)), $body, self::NOW));
        $this->assertNull(DeliveryWebhook::verify(self::SECRET, (string) (self::NOW + 300), self::sign($body, self::NOW + 300), $body, self::NOW));
    }

    /** @return iterable<string, array{string, string, string, string, ?string}> secret, ts, sig, body, expected reason */
    public static function badSignatures(): iterable
    {
        $body = '{"event_id":991,"event":"bounced"}';
        $ts   = (string) self::NOW;
        yield 'no secret configured' => ['', $ts, self::sign($body, $ts), $body, 'not_configured'];
        yield 'wrong secret'         => [self::SECRET, $ts, self::sign($body, $ts, 'other-secret'), $body, 'invalid_signature'];
        yield 'body tampered'        => [self::SECRET, $ts, self::sign($body, $ts), str_replace('bounced', 'relayed', $body), 'invalid_signature'];
        yield 'timestamp swapped'    => [self::SECRET, (string) (self::NOW - 1), self::sign($body, $ts), $body, 'invalid_signature'];
        yield 'no prefix'            => [self::SECRET, $ts, hash_hmac('sha256', $ts . '.' . $body, self::SECRET), $body, 'invalid_signature'];
        yield 'empty signature'      => [self::SECRET, $ts, '', $body, 'invalid_signature'];
        yield 'non-numeric ts'       => [self::SECRET, 'yesterday', self::sign($body, 'yesterday'), $body, 'invalid_signature'];
        yield 'older than 5 min'     => [self::SECRET, (string) (self::NOW - 301), self::sign($body, self::NOW - 301), $body, 'stale_timestamp'];
        yield 'more than 5 min ahead' => [self::SECRET, (string) (self::NOW + 301), self::sign($body, self::NOW + 301), $body, 'stale_timestamp'];
    }

    /** @dataProvider badSignatures */
    public function testBadSignaturesAreRefused(string $secret, string $ts, string $sig, string $body, string $reason): void
    {
        $this->assertSame($reason, DeliveryWebhook::verify($secret, $ts, $sig, $body, self::NOW));
    }

    public function testHandleRefusesBeforeTouchingTheDatabase(): void
    {
        $body = '{"event_id":5,"event":"relayed"}';
        $this->assertSame([503, 'not_configured'], self::outcome(self::hook('')->handle($body, (string) self::NOW, self::sign($body, self::NOW))));
        $this->assertSame([401, 'invalid_signature'], self::outcome(self::hook()->handle($body, (string) self::NOW, self::sign($body, self::NOW, 'x'))));
        $this->assertSame([401, 'stale_timestamp'], self::outcome(self::hook()->handle($body, (string) (self::NOW - 600), self::sign($body, self::NOW - 600))));
        foreach (['not json', '{"event":"relayed"}', '{"event_id":0,"event":"relayed"}', '{"event_id":7}', '[]'] as $bad) {
            $this->assertSame([400, 'invalid_payload'], self::outcome(self::hook()->handle($bad, (string) self::NOW, self::sign($bad, self::NOW))), $bad);
        }
    }

    /** @return array{0:int, 1:string} */
    private static function outcome(array $r): array
    {
        return [$r['status'], (string) ($r['body']['code'] ?? '')];
    }

    public function testEveryContractEventIsMapped(): void
    {
        $expected = [
            'queued' => 'queued', 'submitted' => 'sending', 'relayed' => 'relayed', 'deferred' => 'deferred', 'bounced' => 'bounced',
            'complained' => 'complained', 'unsubscribed' => 'unsubscribed', 'suppressed' => 'suppressed', 'expired' => 'expired',
            'cancelled' => 'cancelled', 'uncertain' => 'uncertain', 'failover' => null,
        ];
        foreach ($expected as $event => $status) {
            $this->assertSame($status, EmailDeliveryLedger::statusFor($event), $event);
            $this->assertTrue($status === null || isset(EmailDeliveryLedger::RANK[$status]), "{$event} has a rank");
        }
        $this->assertNull(EmailDeliveryLedger::statusFor('something_new'));
    }

    public function testMergeNeverRegressesOnLateOrRepeatedEvents(): void
    {
        $m = static fn (?string $cur, ?string $next): ?string => EmailDeliveryLedger::merge($cur, $next);
        $this->assertSame('sending', $m('queued', 'sending'));
        $this->assertSame('deferred', $m('sending', 'deferred'));
        $this->assertSame('sending', $m('deferred', 'sending'), 'same rank: the later event wins');
        $this->assertSame('relayed', $m('deferred', 'relayed'));
        $this->assertSame('relayed', $m('relayed', 'deferred'), 'a late deferred never hides relayed');
        $this->assertSame('relayed', $m('relayed', 'queued'));
        $this->assertSame('bounced', $m('relayed', 'bounced'), 'an asynchronous bounce after relay');
        $this->assertSame('bounced', $m('bounced', 'relayed'), 'a late relayed never hides a bounce');
        $this->assertSame('complained', $m('relayed', 'complained'));
        $this->assertSame('complained', $m('complained', 'bounced'));
        $this->assertSame('relayed', $m('relayed', null), 'failover changes nothing');
        $this->assertSame('uncertain', $m(null, 'uncertain'));
    }
}

<?php
declare(strict_types=1);

namespace App\Libraries\Seller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A payout week: ISO week, Monday 00:00 – Sunday 23:59:59 IST (contract "Weekly payouts").
 *
 *   code        "2026-W42"           (ISO-8601 week-numbering year + week)
 *   batch_ref   "PB-2026-42"         (Finance batch_ref)
 *   payout_ref  "PO-2026-42-000123"  (Finance item_ref; the number is the seller code's)
 *
 * The run for week W happens on the Monday after it (10:00 IST) and collects commission approved
 * before W ends (cutoff = the following Monday 00:00 IST).
 */
final class PayoutWeek
{
    public const TZ = 'Asia/Kolkata';

    private function __construct(public readonly int $year, public readonly int $week, public readonly DateTimeImmutable $start)
    {
    }

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    /** "2026-W42" (also "2026W42", "2026-w42"). */
    public static function parse(string $code): self
    {
        if (! preg_match('/\A(\d{4})-?[Ww](\d{2})\z/', trim($code), $m)) {
            throw new InvalidArgumentException('Week must look like 2026-W42.');
        }
        $year = (int) $m[1];
        $week = (int) $m[2];
        $start = (new DateTimeImmutable('now', self::tz()))->setISODate($year, $week, 1)->setTime(0, 0);
        if ($week < 1 || (int) $start->format('W') !== $week || (int) $start->format('o') !== $year) {
            throw new InvalidArgumentException("{$year} has no ISO week {$week}.");
        }

        return new self($year, $week, $start);
    }

    /** The week containing the instant $ts. */
    public static function containing(int $ts): self
    {
        $d = (new DateTimeImmutable('@' . $ts))->setTimezone(self::tz());

        return self::parse($d->format('o-\WW'));
    }

    /** The current IST week (in progress). */
    public static function current(?int $now = null): self
    {
        return self::containing($now ?? time());
    }

    /** The last week that has fully ended — what the Monday run pays out. */
    public static function lastCompleted(?int $now = null): self
    {
        return self::containing(($now ?? time()) - 7 * 86400);
    }

    public function code(): string
    {
        return sprintf('%04d-W%02d', $this->year, $this->week);
    }

    public function batchRef(): string
    {
        return sprintf('PB-%04d-%02d', $this->year, $this->week);
    }

    public function payoutRef(string $sellerCode): string
    {
        $digits = preg_replace('/\D+/', '', $sellerCode) ?: '0';

        return sprintf('PO-%04d-%02d-%s', $this->year, $this->week, str_pad($digits, 6, '0', STR_PAD_LEFT));
    }

    public function periodStart(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function periodEnd(): string
    {
        return $this->start->modify('+6 days')->format('Y-m-d');
    }

    /** Unix time of the end of the week (next Monday 00:00 IST, exclusive). */
    public function endsAt(): int
    {
        return $this->start->modify('+7 days')->getTimestamp();
    }

    public function hasEnded(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->endsAt();
    }

    /** Monday 10:00 IST after this week — when the scheduled run pays it out. */
    public function scheduledRunAt(): DateTimeImmutable
    {
        return $this->start->modify('+7 days')->setTime(10, 0);
    }

    public function next(): self
    {
        return self::containing($this->start->modify('+7 days')->getTimestamp() + 3600);
    }

    /** Next Monday-10:00-IST run date (today when it is Monday before 10:00). YYYY-MM-DD. */
    public static function nextRunDate(?int $now = null): string
    {
        $now ??= time();
        $d = (new DateTimeImmutable('@' . $now))->setTimezone(self::tz());
        $monday = $d->modify('monday this week')->setTime(10, 0);
        if ($monday->getTimestamp() <= $now) {
            $monday = $monday->modify('+7 days');
        }

        return $monday->format('Y-m-d');
    }
}

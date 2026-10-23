<?php
declare(strict_types=1);

namespace App\Services\Coupons;

/**
 * A coupon / quote could not be used. $reason is the detailed staff-facing code (e.g. holder_limit, quote_expired);
 * RedemptionService::generic() maps it to the three customer-safe codes Manage may show.
 */
final class CouponException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '', public readonly int $status = 422)
    {
        parent::__construct($message !== '' ? $message : (RedemptionService::REASON_MESSAGES[$reason] ?? $reason));
    }
}

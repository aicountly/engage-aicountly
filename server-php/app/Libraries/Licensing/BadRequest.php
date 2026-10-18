<?php
declare(strict_types=1);

namespace App\Libraries\Licensing;

/** An unparsable query parameter on the Manage read API (answered 400 bad_request, details.field). */
final class BadRequest extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

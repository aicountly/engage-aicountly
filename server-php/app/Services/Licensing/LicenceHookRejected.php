<?php
declare(strict_types=1);

namespace App\Services\Licensing;

/** Thrown by LicenceHooks::afterLicenceWrite() to refuse a licence write (it is rolled back). */
final class LicenceHookRejected extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'validation_failed',
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    /** @return array<string,mixed> a LicenceService result */
    public function result(): array
    {
        $r = ['status' => $this->status, 'code' => $this->errorCode, 'error' => $this->getMessage()];
        if ($this->details !== []) {
            $r['details'] = $this->details;
        }

        return $r;
    }
}

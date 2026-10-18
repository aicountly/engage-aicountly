<?php
declare(strict_types=1);

namespace App\Libraries\AicS2s;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * AIC-S2S v1 envelope (commerce contract v1 §0.2):
 *   success {"success":true,"data":…,"page":{"next_cursor":…,"limit":25}?,"request_id":"…"}
 *   error   {"success":false,"error":{"code","message","details":{…},"retryable"},"request_id":"…"}
 */
final class AicS2sResponse
{
    public static function success(ResponseInterface $response, mixed $data, ?array $page, string $requestId, int $status = 200): ResponseInterface
    {
        $body = ['success' => true, 'data' => $data];
        if ($page !== null) {
            $body['page'] = $page;
        }
        $body['request_id'] = $requestId;

        return $response->setStatusCode($status)
            ->setHeader('X-AIC-Request-Id', $requestId)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setJSON($body);
    }

    /** @param array<string,mixed> $details */
    public static function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
        string $requestId,
        bool $retryable = false,
        array $details = []
    ): ResponseInterface {
        return $response->setStatusCode($status)
            ->setHeader('X-AIC-Request-Id', $requestId)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setJSON([
                'success'    => false,
                'error'      => ['code' => $code, 'message' => $message, 'details' => (object) $details, 'retryable' => $retryable],
                'request_id' => $requestId,
            ]);
    }

    /** The caller's X-AIC-Request-Id when it is a sane token, else a fresh uuid. */
    public static function requestId(string $header): string
    {
        $header = trim($header);

        return preg_match('/\A[A-Za-z0-9._\-]{8,64}\z/', $header) ? $header : AicS2sSignature::uuid4();
    }
}

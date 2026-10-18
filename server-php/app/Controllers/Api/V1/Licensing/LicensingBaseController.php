<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** Staff licensing screens: the usual {ok, data} / {ok:false, error, code?, details?} answers. */
abstract class LicensingBaseController extends BaseApiController
{
    protected function result(array $r): ResponseInterface
    {
        $status = (int) ($r['status'] ?? 500);
        if ($status >= 200 && $status < 300) {
            return $this->ok($r['data'] ?? [], $status);
        }
        $body = ['ok' => false, 'error' => (string) ($r['error'] ?? 'Request failed.')];
        if (isset($r['code'])) {
            $body['code'] = $r['code'];
        }
        if (isset($r['details'])) {
            $body['details'] = $r['details'];
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }
}

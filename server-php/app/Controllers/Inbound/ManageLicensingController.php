<?php
declare(strict_types=1);

namespace App\Controllers\Inbound;

use App\Libraries\AicS2s\AicS2sResponse;
use App\Libraries\Licensing\BadRequest;
use App\Libraries\Licensing\LicensingSupport as S;
use App\Services\Licensing\LicenceService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * manage.aicountly.com (server side) → Engage, read only (commerce contract v1 §4). Filter `aic-s2s:manage`
 * (AIC-S2S v1 HMAC; 503 inbound_not_configured until configured). Customers never reach Engage: Manage verifies the
 * my.aicountly user and company, then asks for that one company.
 *
 *   GET v1/manage/companies/{cmp_id}/licences ?status=&product_code=&limit=&cursor=
 *   GET v1/manage/companies/{cmp_id}/licences/{licence_ref}
 *   GET v1/manage/companies/{cmp_id}/licences/{licence_ref}/seats
 *   GET v1/manage/companies/{cmp_id}/activity ?limit=&cursor=
 *   GET v1/manage/catalogue ?cmp_id=
 *   GET v1/manage/licence-types
 *
 * Every answer uses the §0.2 envelope; every read is audited as manage:<X-AIC-Actor>.
 */
class ManageLicensingController extends Controller
{
    public function licences($cmp = null)
    {
        return $this->guard(function () use ($cmp) {
            $cmpId = $this->cmpId($cmp);
            $status = $this->query('status');
            if ($status !== null && ! in_array($status, LicenceService::STATUSES, true)) {
                return $this->bad('status', 'status must be one of: ' . implode(', ', LicenceService::STATUSES) . '.');
            }
            $product = $this->query('product_code');
            if ($product !== null && ! preg_match('/\A[a-z0-9][a-z0-9_\-]{0,63}\z/', $product)) {
                return $this->bad('product_code', 'product_code is not valid.');
            }
            [$limit, $cursor] = $this->paging();
            $r = Services::manageLicensing()->licences($cmpId, ['status' => $status, 'product_code' => $product], $limit, $cursor);
            $this->audit('manage.licences.list', $cmpId, ['count' => count($r['items']), 'status' => $status, 'product_code' => $product]);

            return $this->okList($r, $limit);
        });
    }

    public function licence($cmp = null, $ref = null)
    {
        return $this->guard(function () use ($cmp, $ref) {
            $cmpId = $this->cmpId($cmp);
            $l = $this->validRef($ref) ? Services::manageLicensing()->licence($cmpId, (string) $ref) : null;
            $this->audit('manage.licence.read', $cmpId, ['licence_ref' => (string) $ref, 'found' => $l !== null]);

            return $l === null ? $this->notFound() : $this->ok($l);
        });
    }

    public function seats($cmp = null, $ref = null)
    {
        return $this->guard(function () use ($cmp, $ref) {
            $cmpId = $this->cmpId($cmp);
            $s = $this->validRef($ref) ? Services::manageLicensing()->seats($cmpId, (string) $ref) : null;
            $this->audit('manage.licence.seats', $cmpId, ['licence_ref' => (string) $ref, 'found' => $s !== null]);

            return $s === null ? $this->notFound() : $this->ok($s);
        });
    }

    public function activity($cmp = null)
    {
        return $this->guard(function () use ($cmp) {
            $cmpId = $this->cmpId($cmp);
            [$limit, $cursor] = $this->paging();
            $r = Services::manageLicensing()->activity($cmpId, $limit, $cursor);
            $this->audit('manage.activity.list', $cmpId, ['count' => count($r['items'])]);

            return $this->okList($r, $limit);
        });
    }

    public function catalogue()
    {
        return $this->guard(function () {
            $cmpId = $this->cmpId($this->query('cmp_id'));
            $items = Services::manageLicensing()->catalogue($cmpId);
            $this->audit('manage.catalogue.read', $cmpId, ['count' => count($items)]);

            return $this->ok($items);
        });
    }

    public function licenceTypes()
    {
        return $this->guard(function () {
            $items = Services::manageLicensing()->licenceTypes();
            $this->audit('manage.licence_types.read', null, ['count' => count($items)]);

            return $this->ok($items);
        });
    }

    /** Any non-GET under v1/manage: a read key never gets here (filter 403); nothing else exists yet. */
    public function noRoute()
    {
        return $this->error(404, 'not_found', 'No such route.');
    }

    // ------------------------------------------------------------------ plumbing

    /** @param callable(): ResponseInterface $fn */
    private function guard(callable $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (BadRequest $e) {
            return $this->error(400, 'bad_request', $e->getMessage(), false, ['field' => $e->field]);
        } catch (\Throwable $e) {
            log_message('error', 'Manage licensing read failed: ' . $e->getMessage());

            return $this->error(503, 'database_unavailable', 'Licences are temporarily unavailable.', true);
        }
    }

    private function cmpId(mixed $v): int
    {
        if (! is_string($v) || ! preg_match('/\A[1-9]\d{0,17}\z/', $v)) {
            throw new BadRequest('cmp_id', 'cmp_id must be a positive company id.');
        }

        return (int) $v;
    }

    private function query(string $k): ?string
    {
        $v = $this->request->getGet($k);
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v)) {
            throw new BadRequest($k, "{$k} must be a single value.");
        }

        return $v;
    }

    /** @return array{0:int, 1:?array{d:string,id:int}} */
    private function paging(): array
    {
        $l = $this->query('limit');
        if ($l !== null && (! ctype_digit($l) || (int) $l < 1 || (int) $l > 100)) {
            throw new BadRequest('limit', 'limit must be 1–100.');
        }
        $c = $this->query('cursor');
        $cursor = $c === null ? null : S::decodeCursor($c);
        if ($c !== null && $cursor === null) {
            throw new BadRequest('cursor', 'cursor is not valid.');
        }

        return [$l === null ? 25 : (int) $l, $cursor];
    }

    private function validRef(mixed $ref): bool
    {
        return is_string($ref) && preg_match('/\A[A-Za-z0-9_\-]{1,32}\z/', $ref) === 1;
    }

    private function rid(): string
    {
        return (string) ($this->request->aicS2s['request_id'] ?? AicS2sResponse::requestId(''));
    }

    private function ok(mixed $data): ResponseInterface
    {
        return AicS2sResponse::success($this->response, $data, null, $this->rid());
    }

    private function okList(array $r, int $limit): ResponseInterface
    {
        return AicS2sResponse::success($this->response, $r['items'], ['next_cursor' => $r['next_cursor'], 'limit' => $limit], $this->rid());
    }

    private function notFound(): ResponseInterface
    {
        return $this->error(404, 'not_found', 'No such licence for this company.');
    }

    private function bad(string $field, string $msg): ResponseInterface
    {
        return $this->error(400, 'bad_request', $msg, false, ['field' => $field]);
    }

    private function error(int $status, string $code, string $msg, bool $retry = false, array $details = []): ResponseInterface
    {
        return AicS2sResponse::error($this->response, $status, $code, $msg, $this->rid(), $retry, $details);
    }

    private function audit(string $event, ?int $cmpId, array $meta): void
    {
        $ctx = $this->request->aicS2s ?? [];
        Services::auditService()->log($event, [
            'actor_id'     => null,
            'actor_email'  => 'manage:' . ($ctx['actor'] ?? 'unknown'),
            'actor_role'   => 'service:manage',
            'subject_kind' => $cmpId !== null ? 'company' : 'catalogue',
            'subject_id'   => $cmpId,
            'metadata'     => $meta + [
                'request_id' => $ctx['request_id'] ?? null,
                'key_id'     => $ctx['key_id'] ?? null,
                'path'       => '/' . ltrim((string) $this->request->getPath(), '/'),
            ],
        ]);
    }
}

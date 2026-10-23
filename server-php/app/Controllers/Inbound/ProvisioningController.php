<?php
declare(strict_types=1);

namespace App\Controllers\Inbound;

use App\Libraries\AicS2s\AicS2sResponse;
use App\Services\Licensing\ProvisioningService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * my.aicountly (server side) → Engage, Basic provisioning (contract §3.2; docs/LICENSING.md §5b).
 * Filter `aic-s2s:myaicountly,issue` (AIC-S2S v1; key scope issue; 503 inbound_not_configured until configured).
 *
 *   POST v1/provisioning/accounts  {holder_uuid, email, name, signed_up_at, request_id}
 *   → 200 {success, data:{holder_uuid, licences:[{licence_ref, product_code, plan{code,label}, status}]}, request_id}
 *
 * Idempotent per (holder_uuid, product): a retry answers the same licences. Products with no published default
 * offer are skipped (picked up later by `php spark engage:provision-basic --pending`).
 */
class ProvisioningController extends Controller
{
    public function accounts()
    {
        $ctx = $this->request->aicS2s ?? [];
        $rid = (string) ($ctx['request_id'] ?? AicS2sResponse::requestId(''));
        $body = json_decode((string) $this->request->getBody(), true);
        if (! is_array($body) || array_is_list($body)) {
            return AicS2sResponse::error($this->response, 400, 'bad_request', 'The body must be a JSON object.', $rid);
        }
        [$holder, $errors] = ProvisioningService::validate($body);
        if ($holder === null) {
            return AicS2sResponse::error($this->response, 422, 'validation_failed', 'Please correct the request.', $rid, false, $errors);
        }
        try {
            $r = Services::provisioning()->provision($holder);
        } catch (\Throwable $e) {
            log_message('error', 'Basic provisioning failed for {holder}: {msg}', ['holder' => $holder['holder_uuid'], 'msg' => $e->getMessage()]);

            return AicS2sResponse::error($this->response, 503, 'database_unavailable', 'Provisioning is temporarily unavailable.', $rid, true);
        }
        Services::auditService()->log('provisioning.accounts', [
            'actor_id'     => null,
            'actor_email'  => 'myaicountly:' . ($ctx['actor'] ?? 'unknown'),
            'actor_role'   => 'service:myaicountly',
            'subject_kind' => 'holder',
            'subject_id'   => $holder['holder_uuid'],
            'metadata'     => ['created' => $r['created'], 'licences' => count($r['licences']), 'request_id' => $rid, 'body_request_id' => $holder['request_id'], 'key_id' => $ctx['key_id'] ?? null],
        ]);

        return AicS2sResponse::success($this->response, ['holder_uuid' => $r['holder_uuid'], 'licences' => $r['licences']], null, $rid);
    }
}

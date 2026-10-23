<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1\Licensing;

use Config\Services;

/**
 * Staff: Engage → Manage entitlement push status (Licensing → Manage sync). Read: every signed-in role;
 * re-queue: super_admin | sales_manager.
 *   GET  v1/entitlements/outbox ?status=&holder_uuid=&licence_ref=&limit=   {summary, manage, items}
 *   POST v1/entitlements/outbox/{id}/requeue     queue the CURRENT state of that row's (holder, app) again
 */
class EntitlementsController extends LicensingBaseController
{
    public function outbox()
    {
        $q = $this->request->getGet();
        $outbox = Services::entitlementOutbox();

        return $this->ok([
            'summary' => $outbox->summary(),
            'manage'  => ['configured' => $outbox->client()->isConfigured(), 'status' => $outbox->client()->status()],
            'items'   => $outbox->list(is_array($q) ? $q : []),
        ]);
    }

    public function requeue($id = null)
    {
        $w = Services::entitlementOutbox()->requeue((int) $id);
        if ($w === null) {
            return $this->fail('Outbox row not found.', 404);
        }
        Services::auditService()->log('entitlement.requeued', ['subject_kind' => 'entitlement_outbox', 'subject_id' => (int) $id, 'metadata' => $w, 'fanout_console' => true]);

        return $this->ok($w);
    }
}

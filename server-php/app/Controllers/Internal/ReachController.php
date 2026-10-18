<?php

namespace App\Controllers\Internal;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * POST /api/v1/internal/reach/leads
 *
 * Reach sends a lead payload here after a campaign form submission /
 * landing page conversion. Auth: X-Portal-Token: <REACH_INBOUND_TOKEN>.
 *
 * 201 {result: "created", duplicate: false, lead, …} for a new lead;
 * 200 {result: "updated", duplicate: true, matched_on: "email"|"mobile", lead, …} for a repeat push
 * (stage / sales_status / owner of the existing lead are never changed — see ReachIntakeService).
 */
class ReachController extends BaseApiController
{
    public function ingest()
    {
        $body = $this->input();
        $result = Services::reachIntake()->ingest($body);
        if (empty($result['ok'])) {
            return $this->fail('Invalid payload.', 422, ['errors' => $result['errors'] ?? []]);
        }
        return $this->ok($result, ($result['result'] ?? 'created') === 'created' ? 201 : 200);
    }
}

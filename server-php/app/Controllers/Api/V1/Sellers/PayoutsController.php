<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * Staff: weekly seller payouts (Sellers → Payouts). View: super_admin | sales_manager; run / re-send / cancel: super_admin.
 *   GET  v1/payout-runs                         runs + Finance configuration + schedule
 *   GET  v1/payout-runs/preview ?week=          dry run (default: the current week = next Monday's run); writes nothing
 *   POST v1/payout-runs {week?}                 manual run (default: the last week that ended); idempotent per week
 *   GET  v1/payout-runs/{id}                    run + its payouts
 *   POST v1/payout-runs/{id}/resend             re-send a batch whose delivery to Finance failed
 *   POST v1/payout-runs/{id}/cancel {reason}    cancel a batch Finance never received (entries back to approved)
 *   GET  v1/seller-payouts ?seller_id= &status= &week= &run_id=
 *   GET  v1/seller-payouts/{id}                 payout + entries + status timeline
 */
class PayoutsController extends BaseApiController
{
    public function runs()
    {
        [$page, $limit, $offset] = $this->paging();

        return $this->ok(Services::sellerPayouts()->listRuns($limit, $offset) + ['page' => $page, 'limit' => $limit]);
    }

    public function preview()
    {
        try {
            return $this->ok(Services::sellerPayouts()->preview((string) ($this->request->getGet('week') ?? '')));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, ['week' => $e->getMessage()]);
        }
    }

    public function createRun()
    {
        $week = trim((string) ($this->input()['week'] ?? ''));

        return $this->respondWith(Services::sellerPayouts()->run($week !== '' ? $week : null, 'manual', (int) ($this->user()['id'] ?? 0) ?: null));
    }

    public function showRun($id = null)
    {
        $svc = Services::sellerPayouts();
        $run = $svc->findRun((int) $id);
        if (! $run) {
            return $this->fail('Payout run not found.', 404);
        }

        return $this->ok($svc->runView($run) + ['payouts' => $svc->listPayouts(['run_id' => (int) $id], 500, 0)['items']]);
    }

    public function resend($id = null)
    {
        return $this->respondWith(Services::sellerPayouts()->resend((int) $id, $this->user()));
    }

    public function cancel($id = null)
    {
        return $this->respondWith(Services::sellerPayouts()->cancelRun((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function payouts()
    {
        [$page, $limit, $offset] = $this->paging();

        return $this->ok(Services::sellerPayouts()->listPayouts((array) $this->request->getGet(), $limit, $offset) + ['page' => $page, 'limit' => $limit]);
    }

    public function payout($id = null)
    {
        $p = Services::sellerPayouts()->staffPayout((int) $id);

        return $p ? $this->ok($p) : $this->fail('Payout not found.', 404);
    }
}

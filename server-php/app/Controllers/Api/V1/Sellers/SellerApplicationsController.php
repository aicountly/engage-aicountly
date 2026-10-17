<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use Config\Services;

/**
 * Staff: seller applications queue.
 *   GET  v1/seller-applications            ?status=requested|under_review|approved|rejected &q=
 *   GET  v1/seller-applications/{id}
 *   POST v1/seller-applications/{id}/review   → under_review
 *   POST v1/seller-applications/{id}/approve  {note?} → seller + activation {email_sent, link (only when no email was sent)}
 *   POST v1/seller-applications/{id}/reject   {reason}
 * Viewing: super_admin | sales_manager | seller_manager | kyc_reviewer. Decisions: super_admin | seller_manager.
 */
class SellerApplicationsController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->paging();
        $q = $this->request->getGet();
        $b = Services::sellerAccounts()->db()->table('engage_seller_applications');
        if (! empty($q['status'])) {
            $b->where('status', (string) $q['status']);
        }
        if (! empty($q['q'])) {
            $b->groupStart()->like('name', (string) $q['q'], 'both', null, true)->orLike('email', (string) $q['q'], 'both', null, true)
                ->orLike('organization', (string) $q['q'], 'both', null, true)->orLike('application_code', (string) $q['q'], 'both', null, true)->groupEnd();
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $a = Services::sellerApplications()->find((int) $id);

        return $a ? $this->ok($a) : $this->fail('Application not found.', 404);
    }

    public function review($id = null)
    {
        return $this->respondWith(Services::sellerApplications()->markUnderReview((int) $id, $this->user()));
    }

    public function approve($id = null)
    {
        $in = $this->input();

        return $this->respondWith(Services::sellerApplications()->approve((int) $id, $this->user(), isset($in['note']) ? (string) $in['note'] : null));
    }

    public function reject($id = null)
    {
        return $this->respondWith(Services::sellerApplications()->reject((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }
}

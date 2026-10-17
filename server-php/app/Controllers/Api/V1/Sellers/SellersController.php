<?php

namespace App\Controllers\Api\V1\Sellers;

use App\Controllers\BaseApiController;
use App\Controllers\SellerPortal\KycController;
use Config\Services;

/**
 * Staff: sellers.
 *   GET  v1/sellers                          ?status= &kyc_status= &q=
 *   GET  v1/sellers/{id}                     detail (KYC masked, leads, commission totals, agreements)
 *   POST v1/sellers/{id}/suspend|reactivate|terminate   {reason}
 *   POST v1/sellers/{id}/resend-activation | reset-password | unlock
 *   GET  v1/sellers/{id}/kyc                 KYC (masked) + documents
 *   GET  v1/sellers/{id}/kyc/documents/{docId}   the file
 *   POST v1/sellers/{id}/kyc/verify | kyc/reject {reason} | kyc/reveal (full numbers, audited)
 *   GET  v1/seller-kyc                       KYC queue (?status=kyc_submitted default)
 */
class SellersController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->paging();

        return $this->ok(Services::sellerAdmin()->list((array) $this->request->getGet(), $limit, $offset) + ['page' => $page, 'limit' => $limit]);
    }

    public function show($id = null)
    {
        $d = Services::sellerAdmin()->detail((int) $id);

        return $d ? $this->ok($d) : $this->fail('Seller not found.', 404);
    }

    public function suspend($id = null)
    {
        return $this->respondWith(Services::sellerAdmin()->suspend((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function reactivate($id = null)
    {
        return $this->respondWith(Services::sellerAdmin()->reactivate((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function terminate($id = null)
    {
        return $this->respondWith(Services::sellerAdmin()->terminate((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function resendActivation($id = null)
    {
        return $this->respondWith(Services::sellerAdmin()->resendActivation((int) $id, $this->user()));
    }

    public function resetPassword($id = null)
    {
        return $this->respondWith(Services::sellerAdmin()->resetPassword((int) $id, $this->user()));
    }

    public function unlock($id = null)
    {
        return $this->respondWith(Services::sellerAdmin()->unlock((int) $id, $this->user()));
    }

    public function kyc($id = null)
    {
        if (! Services::sellerAccounts()->find((int) $id)) {
            return $this->fail('Seller not found.', 404);
        }

        return $this->ok(Services::sellerKyc()->staffView((int) $id));
    }

    public function kycDocument($id = null, $docId = null)
    {
        $r = Services::sellerKyc()->read((int) $id, (string) $docId);
        if ($r['status'] !== 200) {
            return $this->fail((string) $r['error'], $r['status']);
        }
        $this->audit('seller.kyc_document_viewed', ['subject_kind' => 'seller', 'subject_id' => (int) $id, 'metadata' => ['document_id' => (int) $docId]]);

        return KycController::fileResponse($this->response, $r['meta'], $r['bytes']);
    }

    public function kycVerify($id = null)
    {
        return $this->respondWith(Services::sellerKyc()->verify((int) $id, $this->user()));
    }

    public function kycReject($id = null)
    {
        return $this->respondWith(Services::sellerKyc()->reject((int) $id, $this->user(), (string) ($this->input()['reason'] ?? '')));
    }

    public function kycReveal($id = null)
    {
        return $this->respondWith(Services::sellerKyc()->reveal((int) $id, $this->user()));
    }

    public function kycQueue()
    {
        [$page, $limit, $offset] = $this->paging();
        $status = (string) ($this->request->getGet('status') ?: 'kyc_submitted');
        $b = Services::sellerAccounts()->db()->table('engage_seller_kyc k')
            ->select('k.seller_id, k.legal_name, k.entity_type, k.status, k.submitted_at, k.reviewed_at, k.submission_count, s.seller_code, s.name, s.email, s.status AS seller_status')
            ->join('engage_sellers s', 's.id = k.seller_id')->where('k.status', $status);
        $total = $b->countAllResults(false);

        return $this->ok(['items' => $b->orderBy('k.submitted_at', 'ASC')->limit($limit, $offset)->get()->getResultArray(), 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }
}

<?php

namespace App\Controllers\SellerPortal;

use App\Services\Seller\SellerKycService;
use Config\Services;

/**
 * Seller KYC (portal key + X-Seller-Session):
 *   GET    kyc                   → {kyc: {kyc_status, editable, rejection_reason, submitted_at, verified_at, required_documents, details (masked), documents}}
 *   PUT    kyc                   draft save; absent pan / gstin / bank.account_number = keep; gstin: null = not registered
 *   POST   kyc/submission        {declaration: true}
 *   POST   kyc/documents         multipart: document_type + file   (JSON {document_type, file_name, content_base64} also accepted)
 *   DELETE kyc/documents/{id}    id = "DOC-n"
 *   GET    kyc/documents/{id}    the seller's own file (Engage extra)
 */
class KycController extends BaseSellerPortalController
{
    public function show()
    {
        return $this->ok(Services::sellerKyc()->sellerView((int) $this->seller()['id']));
    }

    public function saveDraft()
    {
        return $this->result(Services::sellerKyc()->saveDraft($this->seller(), $this->input()));
    }

    public function submit()
    {
        return $this->result(Services::sellerKyc()->submit($this->seller(), $this->input()['declaration'] ?? null, $this->clientIp()));
    }

    public function upload()
    {
        if ($limited = $this->throttle('kyc-upload:' . $this->seller()['id'], 30, HOUR)) {
            return $limited;
        }
        $file = $this->request->getFile('file');
        if ($file !== null) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->failCode(413, 'file_too_large', 'The file is larger than 5 MB.');
            }
            if (! $file->isValid()) {
                return $this->failCode(422, 'validation_failed', 'The upload did not complete. Try again.', ['file' => $file->getErrorString()]);
            }
            if ($file->getSize() > SellerKycService::MAX_BYTES) {
                return $this->failCode(413, 'file_too_large', 'The file is larger than 5 MB.');
            }
            $bytes = (string) file_get_contents($file->getTempName());
            $name = $file->getClientName();
            $docType = (string) ($this->request->getPost('document_type') ?? $this->request->getPost('doc_type') ?? '');
        } else {
            $in = $this->input();
            $b64 = (string) ($in['content_base64'] ?? '');
            if ($b64 === '') {
                return $this->failCode(422, 'validation_failed', 'Attach a file.', ['file' => 'Attach a PDF, JPG or PNG file.']);
            }
            if (strlen($b64) > (int) ceil(SellerKycService::MAX_BYTES * 4 / 3) + 8) {
                return $this->failCode(413, 'file_too_large', 'The file is larger than 5 MB.');
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false) {
                return $this->failCode(422, 'validation_failed', 'The file content is not valid base64.', ['file' => 'Invalid file content.']);
            }
            $name = (string) ($in['file_name'] ?? $in['filename'] ?? 'document');
            $docType = (string) ($in['document_type'] ?? $in['doc_type'] ?? '');
        }

        return $this->result(Services::sellerKyc()->upload($this->seller(), $docType, $name, $bytes, $this->clientIp()));
    }

    public function deleteDocument($id = null)
    {
        return $this->result(Services::sellerKyc()->deleteDocument($this->seller(), (string) $id));
    }

    public function download($id = null)
    {
        $r = Services::sellerKyc()->read((int) $this->seller()['id'], (string) $id);
        if ($r['status'] !== 200) {
            return $this->result($r);
        }

        return self::fileResponse($this->response, $r['meta'], $r['bytes']);
    }

    public static function fileResponse($response, array $meta, string $bytes)
    {
        $name = preg_replace('/[^\w.\- ]+/u', '_', (string) $meta['original_name']) ?: 'document';

        return $response->setStatusCode(200)
            ->setHeader('Content-Type', (string) $meta['mime_type'])
            ->setHeader('Content-Length', (string) strlen($bytes))
            ->setHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $name) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($bytes);
    }
}

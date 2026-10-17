<?php

namespace App\Controllers\SellerPortal;

use Config\Services;

/**
 * Seller KYC (session):
 *   GET  kyc                          → {status, details (masked) | null, rejection_reason, documents, required_documents, editable}
 *   PUT  kyc                          {legal_name, entity_type, pan, gstin?, address_line1, address_line2?, city, state,
 *                                       pincode, bank_account_holder, bank_account_number, bank_ifsc}  (also POST)
 *   GET  kyc/documents                → current documents
 *   POST kyc/documents                multipart: doc_type + file  (or JSON {doc_type, filename, content_base64})
 *   GET  kyc/documents/{id}           → the file (the seller's own documents only)
 */
class KycController extends BaseSellerPortalController
{
    public function show()
    {
        return $this->ok(Services::sellerKyc()->sellerView((int) $this->seller()['id']));
    }

    public function submit()
    {
        if ($r = $this->requireSettled()) {
            return $r;
        }

        return $this->result(Services::sellerKyc()->submit($this->seller(), $this->input(), $this->clientIp()));
    }

    public function documents()
    {
        return $this->ok(['items' => Services::sellerKyc()->documents((int) $this->seller()['id'])]);
    }

    public function upload()
    {
        if ($r = $this->requireSettled()) {
            return $r;
        }
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
            if ($file->getSize() > \App\Services\Seller\SellerKycService::MAX_BYTES) {
                return $this->failCode(413, 'file_too_large', 'The file is larger than 5 MB.');
            }
            $bytes = (string) file_get_contents($file->getTempName());
            $name = $file->getClientName();
            $docType = (string) ($this->request->getPost('doc_type') ?? '');
        } else {
            $in = $this->input();
            $b64 = (string) ($in['content_base64'] ?? '');
            if ($b64 === '') {
                return $this->failCode(422, 'validation_failed', 'Attach a file.', ['file' => 'Attach a PDF, JPG or PNG file.']);
            }
            if (strlen($b64) > (int) ceil(\App\Services\Seller\SellerKycService::MAX_BYTES * 4 / 3) + 8) {
                return $this->failCode(413, 'file_too_large', 'The file is larger than 5 MB.');
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false) {
                return $this->failCode(422, 'validation_failed', 'The file content is not valid base64.', ['file' => 'Invalid file content.']);
            }
            $name = (string) ($in['filename'] ?? 'document');
            $docType = (string) ($in['doc_type'] ?? '');
        }

        return $this->result(Services::sellerKyc()->upload($this->seller(), $docType, $name, $bytes, $this->clientIp()));
    }

    public function download($id = null)
    {
        $r = Services::sellerKyc()->read((int) $this->seller()['id'], (int) $id);
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

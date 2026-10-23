<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\IndianTaxIds;
use App\Libraries\Seller\SellerCrypto;
use Config\Services;

/**
 * Seller KYC: a draft the seller edits (PUT kyc), documents (POST / DELETE kyc/documents), a submission
 * (POST kyc/submission, with a declaration), then staff review (kyc_submitted → kyc_verified | kyc_rejected).
 * Required before any payout; sign-in works meanwhile. Editable while not_started or kyc_rejected.
 *
 * PAN, GSTIN and bank account number are encrypted with ENGAGE_DATA_KEY (SellerCrypto); only the last
 * four characters are kept in clear for the masked display. Documents (≤ 5 MB, PDF / JPG / PNG by content)
 * are encrypted too and written under ENGAGE_SELLER_DOCS_PATH — a directory outside the web root in
 * production (default writable/seller-kyc, denied to the web server by .htaccess).
 * Without ENGAGE_DATA_KEY nothing is stored: 503.
 */
class SellerKycService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    public const DOC_TYPES = ['pan_card', 'cancelled_cheque', 'gst_certificate', 'address_proof'];
    public const MAX_DOCUMENTS = 40;

    public function __construct(private ?SellerAccountService $accounts = null, private ?SellerCrypto $crypto = null)
    {
        $this->accounts ??= Services::sellerAccounts();
        $this->crypto   ??= new SellerCrypto();
    }

    public function isConfigured(): bool
    {
        return $this->crypto->isConfigured();
    }

    public static function docsPath(): string
    {
        $p = trim((string) env('ENGAGE_SELLER_DOCS_PATH', ''));

        return rtrim($p !== '' ? $p : WRITEPATH . 'seller-kyc', '/\\') . DIRECTORY_SEPARATOR;
    }

    private static function ensureDir(): string
    {
        $dir = self::docsPath();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the KYC document directory.');
        }
        if (! is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . 'index.html', '');
        }

        return $dir;
    }

    private function notConfigured(): array
    {
        return ['status' => 503, 'code' => 'kyc_unavailable', 'error' => 'KYC storage is not configured on Engage (ENGAGE_DATA_KEY). Try again later.'];
    }

    public static function docRef(int $id): string
    {
        return 'DOC-' . $id;
    }

    public static function docId(string $ref): int
    {
        return preg_match('/\A(?:DOC-)?(\d{1,18})\z/i', $ref, $m) ? (int) $m[1] : 0;
    }

    /** @return array<string,mixed>|null */
    public function row(int $sellerId): ?array
    {
        return $this->accounts->db()->table('engage_seller_kyc')->where('seller_id', $sellerId)->get()->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> raw rows */
    private function docRows(int $sellerId, bool $currentOnly = true): array
    {
        $b = $this->accounts->db()->table('engage_seller_kyc_documents')->where('seller_id', $sellerId);
        $b = $currentOnly ? $b->where('status', 'current') : $b->where('status <>', 'deleted');

        return $b->orderBy('id', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $sellerId, string $kycStatus, bool $currentOnly = true): array
    {
        $docStatus = $kycStatus === 'kyc_verified' ? 'accepted' : ($kycStatus === 'kyc_rejected' ? 'rejected' : 'uploaded');

        return array_map(static fn (array $r): array => [
            'id'               => self::docRef((int) $r['id']),
            'document_type'    => $r['doc_type'],
            'file_name'        => $r['original_name'],
            'mime_type'        => $r['mime_type'],
            'size_bytes'       => (int) $r['size_bytes'],
            'uploaded_at'      => Clock::iso($r['uploaded_at']),
            'status'           => $r['status'] === 'current' ? $docStatus : 'superseded',
            'rejection_reason' => null,
        ], $this->docRows($sellerId, $currentOnly));
    }

    public static function editable(array $seller): bool
    {
        return in_array($seller['kyc_status'], ['not_started', 'kyc_rejected'], true)
            && ! in_array($seller['status'], ['suspended', 'terminated'], true);
    }

    /** GET kyc (seller portal): {kyc: {...}} with masked numbers. @return array<string,mixed> */
    public function sellerView(int $sellerId): array
    {
        $k = $this->row($sellerId);
        $seller = $this->accounts->find($sellerId);

        return ['kyc' => [
            'kyc_status'         => $seller['kyc_status'],
            'editable'           => self::editable($seller),
            'rejection_reason'   => $seller['kyc_status'] === 'kyc_rejected' ? ($k['rejection_reason'] ?? null) : null,
            'submitted_at'       => Clock::iso($k['submitted_at'] ?? null),
            'verified_at'        => $seller['kyc_status'] === 'kyc_verified' ? Clock::iso($k['reviewed_at'] ?? null) : null,
            'required_documents' => self::requiredDocs($k !== null && ! empty($k['gstin_enc'])),
            'details'            => $k ? self::maskedDetails($k) : null,
            'documents'          => $this->documents($sellerId, (string) $seller['kyc_status']),
            'storage_ready'      => $this->isConfigured(),
        ]];
    }

    /** Masked view for staff, plus review fields. @return array<string,mixed> */
    public function staffView(int $sellerId): array
    {
        $v = $this->sellerView($sellerId)['kyc'];
        $k = $this->row($sellerId);
        $seller = $this->accounts->find($sellerId);
        $v['rejection_reason'] = $k['rejection_reason'] ?? null;
        $v['record_status'] = $k['status'] ?? null;
        $v['reviewed_by'] = $k['reviewed_by'] ?? null;
        $v['reviewed_at'] = Clock::iso($k['reviewed_at'] ?? null);
        $v['submission_count'] = (int) ($k['submission_count'] ?? 0);
        $v['declaration_at'] = Clock::iso($k['declaration_at'] ?? null);
        $v['all_documents'] = $this->documents($sellerId, (string) $seller['kyc_status'], false);

        return $v;
    }

    /** @param array<string,mixed> $k */
    private static function maskedDetails(array $k): array
    {
        return [
            'legal_name'   => $k['legal_name'],
            'entity_type'  => $k['entity_type'],
            'pan_masked'   => $k['pan_last4'] ? 'XXXXXX' . $k['pan_last4'] : null,
            'gstin_masked' => $k['gstin_last4'] ? ($k['gst_state_code'] ?? 'XX') . 'XXXXXXXXX' . $k['gstin_last4'] : null,
            'address'      => [
                'line1' => $k['address_line1'], 'line2' => $k['address_line2'], 'city' => $k['city'],
                'state' => $k['state'], 'pincode' => $k['pincode'],
            ],
            'bank'         => [
                'account_holder'        => $k['bank_account_holder'],
                'account_number_masked' => $k['bank_account_last4'] ? 'XXXXXXXX' . $k['bank_account_last4'] : null,
                'ifsc'                  => $k['bank_ifsc'],
                'bank_name'             => null,
            ],
        ];
    }

    /** @return list<string> */
    private static function requiredDocs(bool $hasGstin): array
    {
        return $hasGstin ? ['pan_card', 'cancelled_cheque', 'address_proof', 'gst_certificate'] : ['pan_card', 'cancelled_cheque', 'address_proof'];
    }

    private function locked(array $seller): array
    {
        if (in_array($seller['status'], ['suspended', 'terminated'], true)) {
            return $this->accounts->writeBlock($seller) ?? ['status' => 409, 'code' => 'kyc_locked', 'error' => 'KYC cannot be changed now.'];
        }

        return ['status' => 409, 'code' => 'kyc_locked', 'error' => $seller['kyc_status'] === 'kyc_verified'
            ? 'Your KYC is verified. Contact Aicountly to change it.' : 'Your KYC is under review and cannot be changed now.'];
    }

    /**
     * PUT kyc — save the draft. Absent key = keep what is on file (pan, gstin and bank.account_number are
     * only re-typed to change them); gstin: null = not GST-registered. Formats are checked for what is given.
     *
     * @return array<string,mixed>
     */
    public function saveDraft(array $seller, array $in): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }
        if ($block = $this->accounts->writeBlock($seller)) {
            return $block;
        }
        if (! self::editable($seller)) {
            return $this->locked($seller);
        }
        $k = $this->row((int) $seller['id']);
        $e = [];
        $upd = [];
        $addr = is_array($in['address'] ?? null) ? $in['address'] : [];
        $bank = is_array($in['bank'] ?? null) ? $in['bank'] : [];

        if (array_key_exists('legal_name', $in)) {
            $v = trim((string) $in['legal_name']);
            if (mb_strlen($v) < 2 || mb_strlen($v) > 191) {
                $e['legal_name'] = 'Enter the legal name exactly as on the PAN.';
            }
            $upd['legal_name'] = $v;
        }
        $entity = $k['entity_type'] ?? null;
        if (array_key_exists('entity_type', $in)) {
            $entity = (string) $in['entity_type'];
            if (! in_array($entity, IndianTaxIds::ENTITY_TYPES, true)) {
                $e['entity_type'] = 'Choose individual, proprietorship, partnership, LLP or company.';
            }
            $upd['entity_type'] = $entity;
        }
        $pan = null;
        if (array_key_exists('pan', $in) && $in['pan'] !== null) {
            $pan = IndianTaxIds::normalise((string) $in['pan']);
            if (! IndianTaxIds::isPan($pan)) {
                $e['pan'] = 'Enter a valid PAN (e.g. ABCDE1234F).';
            } elseif ($entity !== null && ($m = IndianTaxIds::panEntityMismatch($pan, (string) $entity)) !== null) {
                $e['pan'] = $m;
            } else {
                $upd['pan_enc'] = $this->crypto->encrypt($pan);
                $upd['pan_last4'] = substr($pan, -4);
            }
        } elseif (! empty($k['pan_enc'])) {
            $pan = $this->crypto->decrypt((string) $k['pan_enc']);
            if ($entity !== null && array_key_exists('entity_type', $in) && ($m = IndianTaxIds::panEntityMismatch($pan, (string) $entity)) !== null) {
                $e['entity_type'] = $m;
            }
        }
        if (array_key_exists('gstin', $in)) {
            if ($in['gstin'] === null || trim((string) $in['gstin']) === '') {
                $upd['gstin_enc'] = null;
                $upd['gstin_last4'] = null;
                $upd['gst_state_code'] = null;
            } else {
                $g = IndianTaxIds::normalise((string) $in['gstin']);
                if (! IndianTaxIds::isGstin($g)) {
                    $e['gstin'] = 'Enter a valid GSTIN (15 characters, valid state code and check character).';
                } elseif ($pan !== null && IndianTaxIds::isPan($pan) && substr($g, 2, 10) !== $pan) {
                    $e['gstin'] = 'The GSTIN does not belong to this PAN.';
                } else {
                    $upd['gstin_enc'] = $this->crypto->encrypt($g);
                    $upd['gstin_last4'] = substr($g, -4);
                    $upd['gst_state_code'] = IndianTaxIds::gstStateCode($g);
                }
            }
        }
        foreach (['line1' => ['address_line1', 191, 'Enter the address.'], 'line2' => ['address_line2', 191, null],
                  'city' => ['city', 96, 'Enter the city.'], 'state' => ['state', 96, 'Enter the state.']] as $key => [$col, $max, $msg]) {
            if (array_key_exists($key, $addr)) {
                $v = trim((string) ($addr[$key] ?? ''));
                if (($msg !== null && $v === '') || mb_strlen($v) > $max) {
                    $e['address.' . $key] = $msg ?? "Keep this under {$max} characters.";
                }
                $upd[$col] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('pincode', $addr)) {
            $v = trim((string) $addr['pincode']);
            if (! IndianTaxIds::isPincode($v)) {
                $e['address.pincode'] = 'Enter a 6-digit PIN code.';
            }
            $upd['pincode'] = $v;
        }
        if (array_key_exists('account_holder', $bank)) {
            $v = trim((string) $bank['account_holder']);
            if (mb_strlen($v) < 2 || mb_strlen($v) > 191) {
                $e['bank.account_holder'] = 'Enter the account holder name.';
            }
            $upd['bank_account_holder'] = $v;
        }
        if (array_key_exists('account_number', $bank) && $bank['account_number'] !== null) {
            $acct = preg_replace('/\s+/', '', (string) $bank['account_number']) ?? '';
            if (! IndianTaxIds::isBankAccount($acct)) {
                $e['bank.account_number'] = 'Enter the bank account number (9 to 18 digits).';
            } else {
                $upd['bank_account_enc'] = $this->crypto->encrypt($acct);
                $upd['bank_account_last4'] = substr($acct, -4);
            }
        }
        if (array_key_exists('ifsc', $bank)) {
            $v = IndianTaxIds::normalise((string) $bank['ifsc']);
            if (! IndianTaxIds::isIfsc($v)) {
                $e['bank.ifsc'] = 'Enter a valid IFSC (e.g. HDFC0001234).';
            }
            $upd['bank_ifsc'] = $v;
        }
        if ($e !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the highlighted fields.', 'details' => $e];
        }

        $db = $this->accounts->db();
        $upd['updated_at'] = Clock::now();
        if ($k) {
            $db->table('engage_seller_kyc')->where('id', (int) $k['id'])->update($upd);
        } else {
            $db->table('engage_seller_kyc')->insert($upd + ['seller_id' => (int) $seller['id'], 'status' => 'draft']);
        }
        Services::sellerAuth()->audit('seller.kyc_draft_saved', $seller, ['fields' => array_values(array_diff(array_keys($upd), ['updated_at', 'pan_enc', 'gstin_enc', 'bank_account_enc']))]);

        return ['status' => 200, 'data' => $this->sellerView((int) $seller['id'])];
    }

    /** POST kyc/submission {declaration: true}. @return array<string,mixed> */
    public function submit(array $seller, mixed $declaration, string $ip): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }
        if ($block = $this->accounts->writeBlock($seller)) {
            return $block;
        }
        if (! self::editable($seller)) {
            return $this->locked($seller);
        }
        if ($declaration !== true) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Confirm the declaration to submit your KYC.', 'details' => ['declaration' => 'Confirm that the details are correct.']];
        }
        $k = $this->row((int) $seller['id']);
        $missing = [];
        $need = [
            'legal_name' => 'legal_name', 'entity_type' => 'entity_type', 'pan_enc' => 'pan',
            'address_line1' => 'address.line1', 'city' => 'address.city', 'state' => 'address.state', 'pincode' => 'address.pincode',
            'bank_account_holder' => 'bank.account_holder', 'bank_account_enc' => 'bank.account_number', 'bank_ifsc' => 'bank.ifsc',
        ];
        foreach ($need as $col => $field) {
            if (! $k || ($k[$col] ?? null) === null || $k[$col] === '') {
                $missing[$field] = 'Required.';
            }
        }
        $have = array_unique(array_column($this->docRows((int) $seller['id']), 'doc_type'));
        foreach (array_diff(self::requiredDocs($k !== null && ! empty($k['gstin_enc'])), $have) as $doc) {
            $missing['documents.' . $doc] = 'Upload the ' . str_replace('_', ' ', $doc) . '.';
        }
        if ($missing !== []) {
            return ['status' => 422, 'code' => 'kyc_incomplete', 'error' => 'Complete your details and upload the required documents before submitting.', 'details' => $missing];
        }
        $db = $this->accounts->db();
        $db->query('UPDATE engage_seller_kyc SET submission_count = submission_count + 1 WHERE id = ?', [(int) $k['id']]);
        $db->table('engage_seller_kyc')->where('id', (int) $k['id'])->update([
            'status' => 'kyc_submitted', 'submitted_at' => Clock::now(), 'declaration_at' => Clock::now(), 'declaration_ip' => substr($ip, 0, 64),
            'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => Clock::now(),
        ]);
        $db->table('engage_sellers')->where('id', (int) $seller['id'])->update(['kyc_status' => 'kyc_submitted', 'updated_at' => Clock::now()]);
        Services::sellerAuth()->audit('seller.kyc_submitted', $seller, []);

        return ['status' => 200, 'data' => $this->sellerView((int) $seller['id'])];
    }

    /** POST kyc/documents. @return array<string,mixed> */
    public function upload(array $seller, string $docType, string $originalName, string $bytes, string $ip): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }
        if ($block = $this->accounts->writeBlock($seller)) {
            return $block;
        }
        if (! self::editable($seller)) {
            return $this->locked($seller);
        }
        if (! in_array($docType, self::DOC_TYPES, true)) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Choose the document type.', 'details' => ['document_type' => 'One of: ' . implode(', ', self::DOC_TYPES) . '.']];
        }
        $size = strlen($bytes);
        if ($size === 0) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'The file is empty.', 'details' => ['file' => 'The file is empty.']];
        }
        if ($size > self::MAX_BYTES) {
            return ['status' => 413, 'code' => 'file_too_large', 'error' => 'The file is larger than 5 MB.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! isset(self::MIME[$mime])) {
            return ['status' => 415, 'code' => 'unsupported_type', 'error' => 'Upload a PDF, JPG or PNG file.'];
        }
        $db = $this->accounts->db();
        $count = (int) $db->query('SELECT COUNT(*) AS n FROM engage_seller_kyc_documents WHERE seller_id = ?', [(int) $seller['id']])->getRow()->n;
        if ($count >= self::MAX_DOCUMENTS) {
            return ['status' => 409, 'code' => 'document_limit', 'error' => 'Too many documents uploaded. Contact Aicountly.'];
        }

        $stored = bin2hex(random_bytes(16)) . '.bin';
        $path = self::ensureDir() . $stored;
        if (@file_put_contents($path, $this->crypto->encrypt($bytes), LOCK_EX) === false) {
            return ['status' => 503, 'code' => 'kyc_unavailable', 'error' => 'Could not store the document. Try again later.'];
        }
        @chmod($path, 0600);
        $name = preg_replace('/[^\w.\- ]+/u', '_', basename($originalName)) ?: ('document.' . self::MIME[$mime]);
        $db->transStart();
        $db->table('engage_seller_kyc_documents')->where('seller_id', (int) $seller['id'])->where('doc_type', $docType)
            ->where('status', 'current')->update(['status' => 'superseded']);
        $db->table('engage_seller_kyc_documents')->insert([
            'seller_id'     => (int) $seller['id'],
            'doc_type'      => $docType,
            'original_name' => mb_substr($name, 0, 191),
            'stored_name'   => $stored,
            'mime_type'     => $mime,
            'size_bytes'    => $size,
            'sha256'        => hash('sha256', $bytes),
            'uploaded_ip'   => substr($ip, 0, 64),
        ]);
        $id = (int) $db->insertID();
        $db->transComplete();
        Services::sellerAuth()->audit('seller.kyc_document_uploaded', $seller, ['doc_type' => $docType, 'document_id' => $id]);

        $doc = array_values(array_filter($this->documents((int) $seller['id'], (string) $seller['kyc_status']), static fn ($d) => $d['id'] === self::docRef($id)))[0] ?? null;

        return ['status' => 201, 'data' => ['document' => $doc]];
    }

    /** DELETE kyc/documents/{id}. @return array<string,mixed> */
    public function deleteDocument(array $seller, string $ref): array
    {
        $id = self::docId($ref);
        $db = $this->accounts->db();
        $doc = $id > 0 ? $db->table('engage_seller_kyc_documents')->where('id', $id)->where('seller_id', (int) $seller['id'])
            ->where('status', 'current')->get()->getRowArray() : null;
        if (! $doc) {
            return ['status' => 404, 'code' => 'not_found', 'error' => 'Document not found.'];
        }
        if ($block = $this->accounts->writeBlock($seller)) {
            return $block;
        }
        if (! self::editable($seller)) {
            return $this->locked($seller);
        }
        $db->table('engage_seller_kyc_documents')->where('id', $id)->update(['status' => 'deleted']);
        @unlink(self::docsPath() . basename((string) $doc['stored_name']));
        Services::sellerAuth()->audit('seller.kyc_document_deleted', $seller, ['doc_type' => $doc['doc_type'], 'document_id' => $id]);

        return ['status' => 200, 'data' => ['deleted' => true]];
    }

    /**
     * @return array{status:int, meta?:array<string,mixed>, bytes?:string, code?:string, error?:string}
     */
    public function read(int $sellerId, string $ref): array
    {
        $id = self::docId($ref);
        $doc = $id > 0 ? $this->accounts->db()->table('engage_seller_kyc_documents')->where('id', $id)->where('seller_id', $sellerId)
            ->where('status <>', 'deleted')->get()->getRowArray() : null;
        if (! $doc) {
            return ['status' => 404, 'code' => 'not_found', 'error' => 'Document not found.'];
        }
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }
        $raw = @file_get_contents(self::docsPath() . basename((string) $doc['stored_name']));
        if ($raw === false) {
            return ['status' => 404, 'code' => 'not_found', 'error' => 'The document file is missing.'];
        }
        try {
            $bytes = $this->crypto->decrypt($raw);
        } catch (\Throwable $e) {
            log_message('error', 'KYC document ' . $id . ' could not be decrypted: ' . $e->getMessage());

            return ['status' => 503, 'code' => 'kyc_unavailable', 'error' => 'The document could not be read.'];
        }

        return ['status' => 200, 'meta' => $doc, 'bytes' => $bytes];
    }

    // ------------------------------------------------------------------ staff review

    /** @return array<string,mixed> */
    public function verify(int $sellerId, array $staff): array
    {
        $k = $this->row($sellerId);
        $s = $this->accounts->find($sellerId);
        if (! $s || ! $k) {
            return ['status' => 404, 'error' => 'No KYC submitted for this seller.'];
        }
        if ($k['status'] !== 'kyc_submitted') {
            return ['status' => 409, 'error' => 'Only submitted KYC can be verified (this one is ' . $k['status'] . ').'];
        }
        $db = $this->accounts->db();
        $db->table('engage_seller_kyc')->where('id', (int) $k['id'])->update([
            'status' => 'kyc_verified', 'rejection_reason' => null, 'reviewed_by' => (int) $staff['id'], 'reviewed_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $db->table('engage_sellers')->where('id', $sellerId)->update(['kyc_status' => 'kyc_verified', 'updated_at' => Clock::now()]);
        if ($s['status'] === 'kyc_pending') {
            $this->accounts->changeStatus($sellerId, 'active', 'KYC verified', (int) $staff['id']);
        }
        $this->audit('seller.kyc_verified', $s, []);

        return ['status' => 200, 'data' => $this->staffView($sellerId)];
    }

    /** @return array<string,mixed> */
    public function reject(int $sellerId, array $staff, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required to reject KYC.', 'details' => ['reason' => 'Give a reason the seller can act on.']];
        }
        $k = $this->row($sellerId);
        $s = $this->accounts->find($sellerId);
        if (! $s || ! $k) {
            return ['status' => 404, 'error' => 'No KYC submitted for this seller.'];
        }
        if ($k['status'] !== 'kyc_submitted') {
            return ['status' => 409, 'error' => 'Only submitted KYC can be rejected (this one is ' . $k['status'] . ').'];
        }
        $db = $this->accounts->db();
        $db->table('engage_seller_kyc')->where('id', (int) $k['id'])->update([
            'status' => 'kyc_rejected', 'rejection_reason' => $reason, 'reviewed_by' => (int) $staff['id'], 'reviewed_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $db->table('engage_sellers')->where('id', $sellerId)->update(['kyc_status' => 'kyc_rejected', 'updated_at' => Clock::now()]);
        $this->audit('seller.kyc_rejected', $s, ['reason' => $reason]);

        return ['status' => 200, 'data' => $this->staffView($sellerId)];
    }

    /** Full numbers for a reviewer comparing them with the documents. Audited. @return array<string,mixed> */
    public function reveal(int $sellerId, array $staff): array
    {
        $k = $this->row($sellerId);
        $s = $this->accounts->find($sellerId);
        if (! $s || ! $k || empty($k['pan_enc'])) {
            return ['status' => 404, 'error' => 'No KYC details for this seller.'];
        }
        if (! $this->isConfigured()) {
            return ['status' => 503, 'error' => 'ENGAGE_DATA_KEY is not configured.'];
        }
        try {
            $data = [
                'pan'                 => $this->crypto->decrypt((string) $k['pan_enc']),
                'gstin'               => $k['gstin_enc'] ? $this->crypto->decrypt((string) $k['gstin_enc']) : null,
                'bank_account_number' => $k['bank_account_enc'] ? $this->crypto->decrypt((string) $k['bank_account_enc']) : null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 503, 'error' => 'The KYC numbers could not be decrypted (ENGAGE_DATA_KEY changed?).'];
        }
        $this->audit('seller.kyc_revealed', $s, []);

        return ['status' => 200, 'data' => $data];
    }

    private function audit(string $event, array $s, array $meta): void
    {
        Services::auditService()->log($event, [
            'subject_kind' => 'seller', 'subject_id' => $s['id'],
            'metadata' => array_merge(['seller_code' => $s['seller_code']], $meta), 'fanout_console' => true,
        ]);
    }
}

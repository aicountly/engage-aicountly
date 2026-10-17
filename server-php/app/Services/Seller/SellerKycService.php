<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\IndianTaxIds;
use App\Libraries\Seller\SellerCrypto;
use Config\Services;

/**
 * Seller KYC: details (PAN, optional GSTIN, address, bank account) and documents, then staff review
 * (kyc_submitted → kyc_verified | kyc_rejected). Required before any payout; sign-in works meanwhile.
 *
 * PAN, GSTIN and bank account number are encrypted with ENGAGE_DATA_KEY (SellerCrypto); only the last
 * four characters are kept in clear for display. Documents (max 5 MB, PDF / JPG / PNG by content) are
 * encrypted too and written under ENGAGE_SELLER_DOCS_PATH — a directory outside the web root in
 * production (default writable/seller-kyc, which is denied to the web server by .htaccess).
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

    /** @return array<string,mixed>|null */
    public function row(int $sellerId): ?array
    {
        return $this->accounts->db()->table('engage_seller_kyc')->where('seller_id', $sellerId)->get()->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $sellerId, bool $currentOnly = true): array
    {
        $b = $this->accounts->db()->table('engage_seller_kyc_documents')
            ->select('id, doc_type, original_name, mime_type, size_bytes, status, uploaded_at')->where('seller_id', $sellerId);
        if ($currentOnly) {
            $b->where('status', 'current');
        }
        $rows = $b->orderBy('id', 'DESC')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['size_bytes'] = (int) $r['size_bytes'];
            $r['uploaded_at'] = Clock::iso($r['uploaded_at']);
        }

        return $rows;
    }

    /** Masked view for the seller portal. @return array<string,mixed> */
    public function sellerView(int $sellerId): array
    {
        $k = $this->row($sellerId);
        $seller = $this->accounts->find($sellerId);

        return [
            'status'           => $seller['kyc_status'] ?? 'not_submitted',
            'details'          => $k ? self::maskedDetails($k) : null,
            'rejection_reason' => $k['rejection_reason'] ?? null,
            'submitted_at'     => Clock::iso($k['submitted_at'] ?? null),
            'reviewed_at'      => Clock::iso($k['reviewed_at'] ?? null),
            'documents'        => $this->documents($sellerId),
            'required_documents' => $this->requiredDocs($k ? ! empty($k['gstin_enc']) : false),
            'editable'         => ($seller['kyc_status'] ?? '') !== 'kyc_verified',
            'storage_ready'    => $this->isConfigured(),
        ];
    }

    /** Masked view for staff (plus review fields). @return array<string,mixed> */
    public function staffView(int $sellerId): array
    {
        $v = $this->sellerView($sellerId);
        $k = $this->row($sellerId);
        $v['reviewed_by'] = $k['reviewed_by'] ?? null;
        $v['submission_count'] = (int) ($k['submission_count'] ?? 0);
        $v['all_documents'] = $this->documents($sellerId, false);

        return $v;
    }

    /** @param array<string,mixed> $k */
    private static function maskedDetails(array $k): array
    {
        return [
            'legal_name'          => $k['legal_name'],
            'entity_type'         => $k['entity_type'],
            'pan'                 => SellerCrypto::maskLast4($k['pan_last4']),
            'gstin'               => SellerCrypto::maskLast4($k['gstin_last4']),
            'gst_state_code'      => $k['gst_state_code'],
            'address_line1'       => $k['address_line1'],
            'address_line2'       => $k['address_line2'],
            'city'                => $k['city'],
            'state'               => $k['state'],
            'pincode'             => $k['pincode'],
            'bank_account_holder' => $k['bank_account_holder'],
            'bank_account_number' => SellerCrypto::maskLast4($k['bank_account_last4']),
            'bank_ifsc'           => $k['bank_ifsc'],
        ];
    }

    /** @return list<string> */
    private function requiredDocs(bool $hasGstin): array
    {
        return $hasGstin ? ['pan_card', 'cancelled_cheque', 'address_proof', 'gst_certificate'] : ['pan_card', 'cancelled_cheque', 'address_proof'];
    }

    /** @return array<string,string> */
    public static function validate(array $in): array
    {
        $e = [];
        $legal = trim((string) ($in['legal_name'] ?? ''));
        if (mb_strlen($legal) < 2 || mb_strlen($legal) > 191) {
            $e['legal_name'] = 'Enter the legal name exactly as on the PAN.';
        }
        $et = (string) ($in['entity_type'] ?? '');
        if (! in_array($et, IndianTaxIds::ENTITY_TYPES, true)) {
            $e['entity_type'] = 'Choose individual, proprietorship, partnership, LLP or company.';
        }
        $pan = IndianTaxIds::normalise($in['pan'] ?? '');
        if (! IndianTaxIds::isPan($pan)) {
            $e['pan'] = 'Enter a valid PAN (e.g. ABCDE1234F).';
        } elseif (($m = IndianTaxIds::panEntityMismatch($pan, $et)) !== null) {
            $e['pan'] = $m;
        }
        $gstin = IndianTaxIds::normalise($in['gstin'] ?? '');
        if ($gstin !== '') {
            if (! IndianTaxIds::isGstin($gstin)) {
                $e['gstin'] = 'Enter a valid GSTIN (15 characters, valid state code and check digit).';
            } elseif (IndianTaxIds::isPan($pan) && substr($gstin, 2, 10) !== $pan) {
                $e['gstin'] = 'The GSTIN does not belong to this PAN.';
            }
        }
        foreach (['address_line1' => 'Enter the address.', 'city' => 'Enter the city.', 'state' => 'Enter the state.'] as $f => $msg) {
            $v = trim((string) ($in[$f] ?? ''));
            if ($v === '' || mb_strlen($v) > ($f === 'address_line1' ? 191 : 96)) {
                $e[$f] = $msg;
            }
        }
        if (mb_strlen((string) ($in['address_line2'] ?? '')) > 191) {
            $e['address_line2'] = 'Keep this under 191 characters.';
        }
        if (! IndianTaxIds::isPincode(trim((string) ($in['pincode'] ?? '')))) {
            $e['pincode'] = 'Enter a 6-digit PIN code.';
        }
        $holder = trim((string) ($in['bank_account_holder'] ?? ''));
        if (mb_strlen($holder) < 2 || mb_strlen($holder) > 191) {
            $e['bank_account_holder'] = 'Enter the account holder name.';
        }
        $acct = preg_replace('/\s+/', '', (string) ($in['bank_account_number'] ?? '')) ?? '';
        if (! IndianTaxIds::isBankAccount($acct)) {
            $e['bank_account_number'] = 'Enter the bank account number (9 to 18 digits).';
        }
        if (! IndianTaxIds::isIfsc(IndianTaxIds::normalise($in['bank_ifsc'] ?? ''))) {
            $e['bank_ifsc'] = 'Enter a valid IFSC (e.g. HDFC0001234).';
        }

        return $e;
    }

    /** @return array<string,mixed> */
    public function submit(array $seller, array $in, string $ip): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }
        if ($seller['kyc_status'] === 'kyc_verified') {
            return ['status' => 409, 'code' => 'kyc_locked', 'error' => 'Your KYC is verified. Contact AICOUNTLY to change it.'];
        }
        if ($seller['status'] === 'onboarding') {
            return ['status' => 403, 'code' => 'agreement_required', 'error' => 'Accept the seller agreement first.'];
        }
        $errors = self::validate($in);
        if ($errors !== []) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Please correct the highlighted fields.', 'details' => $errors];
        }
        $pan   = IndianTaxIds::normalise($in['pan']);
        $gstin = IndianTaxIds::normalise($in['gstin'] ?? '');
        $acct  = preg_replace('/\s+/', '', (string) $in['bank_account_number']) ?? '';
        $have  = array_unique(array_column($this->documents((int) $seller['id']), 'doc_type'));
        $missing = array_values(array_diff($this->requiredDocs($gstin !== ''), $have));
        if ($missing !== []) {
            $details = [];
            foreach ($missing as $d) {
                $details['documents.' . $d] = 'Upload the ' . str_replace('_', ' ', $d) . '.';
            }

            return ['status' => 422, 'code' => 'kyc_incomplete', 'error' => 'Upload the required documents before submitting: ' . implode(', ', $missing) . '.', 'details' => $details];
        }

        $row = [
            'legal_name'          => trim((string) $in['legal_name']),
            'entity_type'         => (string) $in['entity_type'],
            'pan_enc'             => $this->crypto->encrypt($pan),
            'pan_last4'           => substr($pan, -4),
            'gstin_enc'           => $gstin !== '' ? $this->crypto->encrypt($gstin) : null,
            'gstin_last4'         => $gstin !== '' ? substr($gstin, -4) : null,
            'gst_state_code'      => $gstin !== '' ? IndianTaxIds::gstStateCode($gstin) : null,
            'address_line1'       => trim((string) $in['address_line1']),
            'address_line2'       => trim((string) ($in['address_line2'] ?? '')) ?: null,
            'city'                => trim((string) $in['city']),
            'state'               => trim((string) $in['state']),
            'pincode'             => trim((string) $in['pincode']),
            'bank_account_holder' => trim((string) $in['bank_account_holder']),
            'bank_account_enc'    => $this->crypto->encrypt($acct),
            'bank_account_last4'  => substr($acct, -4),
            'bank_ifsc'           => IndianTaxIds::normalise($in['bank_ifsc']),
            'status'              => 'kyc_submitted',
            'rejection_reason'    => null,
            'reviewed_by'         => null,
            'reviewed_at'         => null,
            'submitted_at'        => Clock::now(),
            'updated_at'          => Clock::now(),
        ];
        $db = $this->accounts->db();
        $existing = $this->row((int) $seller['id']);
        if ($existing) {
            $db->query('UPDATE engage_seller_kyc SET submission_count = submission_count + 1 WHERE id = ?', [(int) $existing['id']]);
            $db->table('engage_seller_kyc')->where('id', (int) $existing['id'])->update($row);
        } else {
            $db->table('engage_seller_kyc')->insert($row + ['seller_id' => (int) $seller['id']]);
        }
        $db->table('engage_sellers')->where('id', (int) $seller['id'])->update(['kyc_status' => 'kyc_submitted', 'updated_at' => Clock::now()]);
        Services::sellerAuth()->audit('seller.kyc_submitted', $seller, ['ip' => $ip]);

        return ['status' => 200, 'data' => $this->sellerView((int) $seller['id'])];
    }

    /** @return array<string,mixed> */
    public function upload(array $seller, string $docType, string $originalName, string $bytes, string $ip): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfigured();
        }
        if ($seller['kyc_status'] === 'kyc_verified') {
            return ['status' => 409, 'code' => 'kyc_locked', 'error' => 'Your KYC is verified. Contact AICOUNTLY to change documents.'];
        }
        if ($seller['status'] === 'onboarding') {
            return ['status' => 403, 'code' => 'agreement_required', 'error' => 'Accept the seller agreement first.'];
        }
        if (! in_array($docType, self::DOC_TYPES, true)) {
            return ['status' => 422, 'code' => 'validation_failed', 'error' => 'Choose the document type.', 'details' => ['doc_type' => 'One of: ' . implode(', ', self::DOC_TYPES) . '.']];
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
            return ['status' => 409, 'code' => 'document_limit', 'error' => 'Too many documents uploaded. Contact AICOUNTLY.'];
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

        $doc = array_values(array_filter($this->documents((int) $seller['id']), static fn ($d) => $d['id'] === $id))[0] ?? null;

        return ['status' => 201, 'data' => ['document' => $doc]];
    }

    /**
     * @return array{status:int, meta?:array<string,mixed>, bytes?:string, code?:string, error?:string}
     */
    public function read(int $sellerId, int $docId): array
    {
        $doc = $this->accounts->db()->table('engage_seller_kyc_documents')->where('id', $docId)->where('seller_id', $sellerId)->get()->getRowArray();
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
            log_message('error', 'KYC document ' . $docId . ' could not be decrypted: ' . $e->getMessage());

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
        if (! $s || ! $k) {
            return ['status' => 404, 'error' => 'No KYC submitted for this seller.'];
        }
        if (! $this->isConfigured()) {
            return ['status' => 503, 'error' => 'ENGAGE_DATA_KEY is not configured.'];
        }
        try {
            $data = [
                'pan'                 => $this->crypto->decrypt((string) $k['pan_enc']),
                'gstin'               => $k['gstin_enc'] ? $this->crypto->decrypt((string) $k['gstin_enc']) : null,
                'bank_account_number' => $this->crypto->decrypt((string) $k['bank_account_enc']),
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

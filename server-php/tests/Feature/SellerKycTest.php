<?php

namespace Tests\Feature;

use Tests\Support\SellerTestCase;

/**
 * Seller KYC: draft (masked, encrypted at rest), documents, submission, staff review.
 */
final class SellerKycTest extends SellerTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    private function details(array $extra = []): array
    {
        return array_replace_recursive([
            'legal_name'  => 'Ravi Kumar',
            'entity_type' => 'individual',
            'pan'         => 'ABCPE1234F',
            'gstin'       => null,
            'address'     => ['line1' => '12 MG Road', 'line2' => null, 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
            'bank'        => ['account_holder' => 'Ravi Kumar', 'account_number' => '50100012344321', 'ifsc' => 'HDFC0001234'],
        ], $extra);
    }

    private function upload(string $session, string $type, string $bytes = self::PNG, string $name = 'doc.png')
    {
        return $this->portal('post', 'kyc/documents', ['document_type' => $type, 'file_name' => $name, 'content_base64' => base64_encode($bytes)], $session);
    }

    public function testDraftIsMaskedAndEncryptedAtRest(): void
    {
        $s = $this->activeSellerSession();
        $r = $this->portal('put', 'kyc', $this->details(), $s['session']);
        $this->assertSame(200, $this->code($r));
        $kyc = $this->json($r)['data']['kyc'];
        $this->assertSame('not_started', $kyc['kyc_status']);
        $this->assertTrue($kyc['editable']);
        $this->assertSame('XXXXXX234F', $kyc['details']['pan_masked']);
        $this->assertSame('XXXXXXXX4321', $kyc['details']['bank']['account_number_masked']);
        $this->assertStringNotContainsString('ABCPE1234F', (string) $r->response()->getBody());

        $row = $this->db()->table('engage_seller_kyc')->get()->getRowArray();
        $this->assertStringStartsWith('v1:', $row['pan_enc']);
        $this->assertStringNotContainsString('ABCPE1234F', json_encode($row));
        $this->assertStringNotContainsString('50100012344321', json_encode($row));

        // Absent pan / account number keep what is on file; GSTIN is checked against the PAN on file.
        $r = $this->portal('put', 'kyc', ['legal_name' => 'Ravi Kumar Sharma', 'gstin' => '27ABCPE1234F1Z5'], $s['session']);
        $this->assertSame(422, $this->code($r), 'bad GSTIN check character');
        $this->assertArrayHasKey('gstin', $this->json($r)['details']);
        $r = $this->portal('put', 'kyc', ['gstin' => '27AAPFU0939F1ZV'], $s['session']);
        $this->assertSame('The GSTIN does not belong to this PAN.', $this->json($r)['details']['gstin']);
        $r = $this->portal('put', 'kyc', ['legal_name' => 'Ravi Kumar Sharma'], $s['session']);
        $kyc = $this->json($r)['data']['kyc'];
        $this->assertSame('Ravi Kumar Sharma', $kyc['details']['legal_name']);
        $this->assertSame('XXXXXX234F', $kyc['details']['pan_masked']);

        $bad = $this->portal('put', 'kyc', ['pan' => 'BAD', 'bank' => ['ifsc' => 'HDFC1234567', 'account_number' => '12'], 'address' => ['pincode' => '011001']], $s['session']);
        $d = $this->json($bad)['details'];
        $this->assertArrayHasKey('pan', $d);
        $this->assertArrayHasKey('bank.ifsc', $d);
        $this->assertArrayHasKey('bank.account_number', $d);
        $this->assertArrayHasKey('address.pincode', $d);
        $mismatch = $this->portal('put', 'kyc', ['entity_type' => 'company'], $s['session']);
        $this->assertArrayHasKey('entity_type', $this->json($mismatch)['details'], 'an individual PAN is not a company PAN');
    }

    public function testDocumentsAndSubmissionAndReview(): void
    {
        $s = $this->activeSellerSession();
        $this->portal('put', 'kyc', $this->details(), $s['session']);

        $this->assertSame(415, $this->code($this->upload($s['session'], 'pan_card', "MZ\x90\0 not a pdf")));
        $this->assertSame(413, $this->code($this->upload($s['session'], 'pan_card', "\x89PNG\r\n\x1a\n" . str_repeat('A', 5 * 1024 * 1024))));
        $up = $this->upload($s['session'], 'pan_card');
        $this->assertSame(201, $this->code($up));
        $doc = $this->json($up)['data']['document'];
        $this->assertMatchesRegularExpression('/\ADOC-\d+\z/', $doc['id']);
        $this->assertSame('image/png', $doc['mime_type']);
        $this->assertSame('uploaded', $doc['status']);
        // Stored encrypted, outside the database.
        $stored = $this->db()->table('engage_seller_kyc_documents')->get()->getRowArray();
        $raw = file_get_contents(WRITEPATH . 'seller-kyc-test/' . $stored['stored_name']);
        $this->assertStringStartsWith('v1:', $raw);
        // Own document can be downloaded.
        $dl = $this->portal('get', 'kyc/documents/' . $doc['id'], [], $s['session']);
        $this->assertSame(self::PNG, (string) $dl->response()->getBody());

        $inc = $this->portal('post', 'kyc/submission', ['declaration' => true], $s['session']);
        $this->assertSame(422, $this->code($inc));
        $this->assertSame('kyc_incomplete', $this->json($inc)['code']);
        $this->assertArrayHasKey('documents.cancelled_cheque', $this->json($inc)['details']);

        $this->upload($s['session'], 'cancelled_cheque');
        $addr = $this->upload($s['session'], 'address_proof');
        $this->assertSame(200, $this->code($this->portal('delete', 'kyc/documents/' . $this->json($addr)['data']['document']['id'], [], $s['session'])));
        $this->assertSame(422, $this->code($this->portal('post', 'kyc/submission', ['declaration' => true], $s['session'])));
        $this->upload($s['session'], 'address_proof');
        $this->assertSame(422, $this->code($this->portal('post', 'kyc/submission', ['declaration' => false], $s['session'])));
        $sub = $this->portal('post', 'kyc/submission', ['declaration' => true], $s['session']);
        $this->assertSame(200, $this->code($sub));
        $this->assertSame('kyc_submitted', $this->json($sub)['data']['kyc']['kyc_status']);
        $this->assertFalse($this->json($sub)['data']['kyc']['editable']);
        $locked = $this->portal('put', 'kyc', ['legal_name' => 'X Y'], $s['session']);
        $this->assertSame(409, $this->code($locked));
        $this->assertSame('kyc_locked', $this->json($locked)['code']);

        // Staff review: member cannot; kyc_reviewer can.
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member']), 'post', "v1/sellers/{$s['seller_id']}/kyc/verify")));
        $rev = $this->staff(['kyc_reviewer'], 'kyc@aicountly.org');
        $queue = $this->json($this->asStaff($rev, 'get', 'v1/seller-kyc'))['data']['items'];
        $this->assertCount(1, $queue);
        $view = $this->json($this->asStaff($rev, 'get', "v1/sellers/{$s['seller_id']}/kyc"))['data'];
        $this->assertSame('XXXXXX234F', $view['details']['pan_masked']);
        $file = $this->asStaff($rev, 'get', "v1/sellers/{$s['seller_id']}/kyc/documents/" . $view['documents'][0]['id']);
        $this->assertSame(self::PNG, (string) $file->response()->getBody());
        $reveal = $this->json($this->asStaff($rev, 'post', "v1/sellers/{$s['seller_id']}/kyc/reveal"))['data'];
        $this->assertSame('ABCPE1234F', $reveal['pan']);
        $this->assertSame('50100012344321', $reveal['bank_account_number']);
        $this->assertSame(1, $this->db()->table('engage_audit_logs')->where('event', 'seller.kyc_revealed')->countAllResults());

        $this->assertSame(422, $this->code($this->asStaff($rev, 'post', "v1/sellers/{$s['seller_id']}/kyc/reject", ['reason' => ''])));
        $rej = $this->asStaff($rev, 'post', "v1/sellers/{$s['seller_id']}/kyc/reject", ['reason' => 'Cheque is not readable']);
        $this->assertSame('kyc_rejected', $this->json($rej)['data']['kyc_status']);
        $mine = $this->json($this->portal('get', 'kyc', [], $s['session']))['data']['kyc'];
        $this->assertSame('Cheque is not readable', $mine['rejection_reason']);
        $this->assertTrue($mine['editable']);

        $this->upload($s['session'], 'cancelled_cheque');
        $this->portal('post', 'kyc/submission', ['declaration' => true], $s['session']);
        $ver = $this->asStaff($rev, 'post', "v1/sellers/{$s['seller_id']}/kyc/verify");
        $this->assertSame('kyc_verified', $this->json($ver)['data']['kyc_status']);
        $me = $this->json($this->portal('get', 'me', [], $s['session']))['data']['seller'];
        $this->assertSame('active', $me['status'], 'verified KYC activates the seller');
        $this->assertSame(409, $this->code($this->upload($s['session'], 'pan_card')), 'verified KYC is locked');
    }

    public function testGstinRequiresGstCertificate(): void
    {
        $s = $this->activeSellerSession();
        $this->portal('put', 'kyc', $this->details(['entity_type' => 'partnership', 'pan' => 'AAPFU0939F', 'gstin' => '27AAPFU0939F1ZV']), $s['session']);
        $kyc = $this->json($this->portal('get', 'kyc', [], $s['session']))['data']['kyc'];
        $this->assertContains('gst_certificate', $kyc['required_documents']);
        $this->assertSame('27XXXXXXXXX1Z' . 'V', substr($kyc['details']['gstin_masked'], 0, 11) . substr($kyc['details']['gstin_masked'], -3));
        $this->assertSame(200, $this->code($this->portal('put', 'kyc', ['gstin' => null], $s['session'])));
        $kyc = $this->json($this->portal('get', 'kyc', [], $s['session']))['data']['kyc'];
        $this->assertNull($kyc['details']['gstin_masked']);
    }

    public function testNoDataKeyFailsClosed(): void
    {
        $s = $this->activeSellerSession();
        $this->setEnv('ENGAGE_DATA_KEY', null);
        \Config\Services::reset(false);
        \Config\Services::injectMock('sellerMailer', $this->mailer);
        $r = $this->portal('put', 'kyc', $this->details(), $s['session']);
        $this->assertSame(503, $this->code($r));
        $this->assertSame(0, $this->db()->table('engage_seller_kyc')->countAllResults());
    }
}

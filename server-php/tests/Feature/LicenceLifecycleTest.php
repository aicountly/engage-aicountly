<?php

namespace Tests\Feature;

use Config\Services;
use Tests\Support\LicensingTestCase;

/**
 * Account ↔ company mapping and staff licence lifecycle: issue from a published offer (snapshot, term, seats),
 * activate / renew / extend / suspend / reinstate / revoke, seats and seat assignments, invoice refs, expiry,
 * transition rules, optimistic locking, one event per change in the same transaction (append-only, seq per cmp_id),
 * audit, role gates.
 */
final class LicenceLifecycleTest extends LicensingTestCase
{
    private function act(string $ref, string $action, array $body): \CodeIgniter\Test\TestResponse
    {
        return $this->asStaff($this->admin(), 'post', "v1/licences/{$ref}/{$action}", $body);
    }

    public function testCompanyMappingRules(): void
    {
        $admin = $this->admin();
        $a = $this->account('Acme');
        $b = $this->account('Beta');
        $r = $this->asStaff($admin, 'post', 'v1/account-companies', ['account_id' => $a, 'cmp_id' => 42]);
        $this->assertSame(422, $this->code($r), 'a verification note is required');
        $this->assertArrayHasKey('verification_note', $this->json($r)['details']);
        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', 'v1/account-companies', ['account_id' => $a, 'cmp_id' => 'abc', 'verification_note' => 'x'])));

        $id = $this->mapCompany($a, 42);
        $m = $this->json($this->asStaff($admin, 'get', 'v1/account-companies', ['account_id' => $a]))['data']['items'][0];
        $this->assertSame(42, $m['cmp_id']);
        $this->assertSame('verified manually by sales-mgr@aicountly.org', $m['verified_by_display']);
        $this->assertSame('manual', $m['verification_method']);

        $this->assertSame(409, $this->code($this->asStaff($admin, 'post', 'v1/account-companies', ['account_id' => $a, 'cmp_id' => 42, 'verification_note' => 'again'])));
        $other = $this->asStaff($admin, 'post', 'v1/account-companies', ['account_id' => $b, 'cmp_id' => 42, 'verification_note' => 'x']);
        $this->assertSame(409, $this->code($other), 'a company belongs to one account at a time');
        $this->mapCompany($a, 43); // an account may hold several companies

        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member'], 'm@aicountly.org'), 'post', 'v1/account-companies', ['account_id' => $b, 'cmp_id' => 44, 'verification_note' => 'x'])));

        $this->assertSame(422, $this->code($this->asStaff($admin, 'post', "v1/account-companies/{$id}/unlink", ['reason' => ''])));
        $this->assertSame(200, $this->code($this->asStaff($admin, 'post', "v1/account-companies/{$id}/unlink", ['reason' => 'Wrong company'])));
        $this->assertSame(201, $this->code($this->asStaff($admin, 'post', 'v1/account-companies', ['account_id' => $b, 'cmp_id' => 42, 'verification_note' => 'Owner confirmed'])));
        $this->assertSame(3, $this->db()->table('engage_audit_logs')->where('event', 'licence.company_linked')->countAllResults());
    }

    public function testIssueRequiresAMappedCompanyAndAPublishedOffer(): void
    {
        $this->publishedOffer();
        $a = $this->account();
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $a, 'cmp_id' => 42, 'offer_code' => 'books-annual']);
        $this->assertSame(422, $this->code($r));
        $this->assertArrayHasKey('cmp_id', $this->json($r)['details']);
        $this->mapCompany($a, 42);
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $a, 'cmp_id' => 42, 'offer_code' => 'nope']);
        $this->assertSame(422, $this->code($r));
        $this->assertSame(403, $this->code($this->asStaff($this->staff(['member'], 'm@aicountly.org'), 'post', 'v1/licences', ['account_id' => $a, 'cmp_id' => 42, 'offer_code' => 'books-annual'])));
        // A draft version cannot be issued.
        $id = (int) $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->get()->getRow()->id;
        $this->asStaff($this->admin(), 'post', "v1/offers/{$id}/new-version");
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', ['account_id' => $a, 'cmp_id' => 42, 'offer_code' => 'books-annual', 'offer_version' => 2]);
        $this->assertSame(422, $this->code($r));
        $this->assertSame(0, $this->db()->table('engage_licences')->countAllResults());
    }

    public function testIssueSnapshotsTheOfferAndWritesEvents(): void
    {
        $l = $this->issue(42, ['starts_at' => '2026-10-18', 'invoice_refs' => ['AIC/26-27/000001']]);
        $this->assertMatchesRegularExpression('/\ALIC-\d{6}\z/', $l['licence_ref']);
        $this->assertSame('active', $l['status']['code']);
        $this->assertSame('2026-10-17T18:30:00Z', $l['term']['starts_at'], '00:00 IST');
        $this->assertSame('2027-10-17T18:30:00Z', $l['term']['ends_at']);
        $this->assertSame('12 months', $l['term']['cycle']);
        $this->assertSame(['total' => 3, 'assigned' => 0], $l['seats']);
        $this->assertSame(['books'], $l['app_keys']);
        $this->assertSame(['AIC/26-27/000001'], $l['invoice_refs']);
        $this->assertSame('Books — test annual', $l['offer']['label']);
        $this->assertSame('Standard licence', $l['licence_type']['label']);
        $this->assertSame('AICOUNTLY Books', $l['product']['label']);
        $this->assertSame([['code' => 'companies', 'label' => 'Companies', 'value' => 1, 'unit' => null]], $l['entitlements']);
        $this->assertSame([['label' => 'Support', 'value' => 'Email']], $l['attributes']);
        $this->assertSame(1, $l['version']);
        $this->assertSame(['activated', 'issued'], array_column(array_column($l['events'], 'type'), 'code'));
        $this->assertSame([2, 1], array_column($l['events'], 'seq'));
        $this->assertStringContainsString('from 18/10/2026 to 17/10/2027', $l['events'][1]['summary']);
        $this->assertSame('sales-mgr@aicountly.org', $l['events'][0]['actor']['display']);
        $this->assertSame(1, $this->db()->table('engage_audit_logs')->where('event', 'licence.issued')->countAllResults());

        // The snapshot survives a new offer version.
        $id = (int) $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->get()->getRow()->id;
        $v2 = $this->json($this->asStaff($this->admin(), 'post', "v1/offers/{$id}/new-version"))['data'];
        $this->asStaff($this->admin(), 'put', "v1/offers/{$v2['id']}", ['label' => 'Books — renamed']);
        $this->asStaff($this->admin(), 'post', "v1/offers/{$v2['id']}/publish");
        $again = $this->json($this->asStaff($this->admin(), 'get', "v1/licences/{$l['licence_ref']}"))['data'];
        $this->assertSame('Books — test annual', $again['offer']['label']);
        $this->assertSame(1, $again['offer']['version']);
    }

    public function testIssueWithoutActivationThenActivate(): void
    {
        $l = $this->issue(42, ['activate' => false, 'seats_total' => 5]);
        $this->assertSame('issued', $l['status']['code']);
        $this->assertSame(5, $l['seats']['total']);
        $this->assertSame(['issued'], array_column(array_column($l['events'], 'type'), 'code'));
        $r = $this->act($l['licence_ref'], 'activate', ['version' => 1]);
        $this->assertSame(200, $this->code($r));
        $d = $this->json($r)['data'];
        $this->assertSame('active', $d['status']['code']);
        $this->assertSame(2, $d['version']);
        $this->assertSame('activated', $d['events'][0]['type']['code']);
        $this->assertSame(2, $d['events'][0]['licence_version']);
        $this->assertSame(409, $this->code($this->act($l['licence_ref'], 'activate', ['version' => 2])), 'already active');
    }

    public function testSuspendReinstateRevokeWithReasonsAndTransitions(): void
    {
        $l = $this->issue();
        $ref = $l['licence_ref'];
        $r = $this->act($ref, 'suspend', ['version' => 1]);
        $this->assertSame(422, $this->code($r), 'reason required');
        $this->assertArrayHasKey('reason', $this->json($r)['details']);
        $this->assertSame(422, $this->code($this->act($ref, 'suspend', ['reason' => 'x'])), 'version required');

        $d = $this->json($this->act($ref, 'suspend', ['version' => 1, 'reason' => 'Payment dispute']))['data'];
        $this->assertSame('suspended', $d['status']['code']);
        $this->assertSame('Payment dispute', $d['status_reason']);
        $this->assertSame('Payment dispute', $d['events'][0]['reason']);
        $this->assertSame(409, $this->code($this->act($ref, 'suspend', ['version' => 2, 'reason' => 'again'])));
        $this->assertSame(409, $this->code($this->act($ref, 'activate', ['version' => 2])));

        $d = $this->json($this->act($ref, 'reinstate', ['version' => 2, 'reason' => 'Resolved']))['data'];
        $this->assertSame('active', $d['status']['code']);
        $this->assertNull($d['status_reason']);

        $d = $this->json($this->act($ref, 'revoke', ['version' => 3, 'reason' => 'Refunded']))['data'];
        $this->assertSame('revoked', $d['status']['code']);
        foreach (['suspend', 'reinstate', 'renew', 'extend', 'seats', 'activate'] as $a) {
            $r = $this->act($ref, $a, ['version' => 4, 'reason' => 'x', 'ends_at' => '2030-01-01', 'seats_total' => 9]);
            $this->assertSame(409, $this->code($r), $a);
            $this->assertSame('invalid_transition', $this->json($r)['code'], $a);
        }
        $types = array_column(array_column($d['events'], 'type'), 'code');
        $this->assertSame(['revoked', 'reinstated', 'suspended', 'activated', 'issued'], $types);
        $this->assertSame(['licence.suspend', 'licence.reinstate', 'licence.revoke'], array_column(
            $this->db()->table('engage_audit_logs')->whereIn('event', ['licence.suspend', 'licence.reinstate', 'licence.revoke'])->orderBy('id')->get()->getResultArray(), 'event'));
    }

    public function testOptimisticLockRefusesAStaleVersion(): void
    {
        $l = $this->issue();
        $this->assertSame(200, $this->code($this->act($l['licence_ref'], 'suspend', ['version' => 1, 'reason' => 'A'])));
        $r = $this->act($l['licence_ref'], 'revoke', ['version' => 1, 'reason' => 'B']);
        $this->assertSame(409, $this->code($r));
        $this->assertSame('version_conflict', $this->json($r)['code']);
        $this->assertSame(2, $this->json($r)['details']['current_version']);
        $this->assertSame('suspended', $this->db()->table('engage_licences')->get()->getRow()->status);
        $this->assertSame(3, $this->db()->table('engage_licence_events')->countAllResults(), 'no event for the refused change');
        $this->assertSame(404, $this->code($this->act('LIC-999999', 'suspend', ['version' => 1, 'reason' => 'x'])));
    }

    public function testRenewAndExtend(): void
    {
        $l = $this->issue(42, ['starts_at' => '2026-10-18']);
        $ref = $l['licence_ref'];
        $d = $this->json($this->act($ref, 'renew', ['version' => 1, 'invoice_refs' => 'AIC/27-28/000009']))['data'];
        $this->assertSame('2028-10-17T18:30:00Z', $d['term']['ends_at'], 'a term added after the current end');
        $this->assertSame(['AIC/27-28/000009'], $d['invoice_refs']);
        $this->assertSame('renewed', $d['events'][0]['type']['code']);
        $this->assertEquals(['from' => '2027-10-17T18:30:00Z', 'to' => '2028-10-17T18:30:00Z'], $d['events'][0]['changes']['ends_at']);
        $this->assertSame('Licence renewed until 17/10/2028', $d['events'][0]['summary']);
        $this->assertSame(422, $this->code($this->act($ref, 'renew', ['version' => 2, 'offer_code' => 'other'])), 'no upgrade through renew');

        $r = $this->act($ref, 'extend', ['version' => 2, 'ends_at' => '2028-01-01', 'reason' => 'Goodwill']);
        $this->assertSame(422, $this->code($r), 'earlier than the current end');
        $d = $this->json($this->act($ref, 'extend', ['version' => 2, 'ends_at' => '2028-12-31', 'reason' => 'Goodwill']))['data'];
        $this->assertSame('2028-12-30T18:30:00Z', $d['term']['ends_at']);
        $this->assertSame('extended', $d['events'][0]['type']['code']);
        $this->assertSame(3, $d['version']);
    }

    public function testRenewOntoANewOfferVersionUpdatesTheSnapshot(): void
    {
        $l = $this->issue();
        $id = (int) $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->get()->getRow()->id;
        $v2 = $this->json($this->asStaff($this->admin(), 'post', "v1/offers/{$id}/new-version"))['data'];
        $this->asStaff($this->admin(), 'put', "v1/offers/{$v2['id']}", ['label' => 'Books — v2', 'entitlements' => [['code' => 'companies', 'label' => 'Companies', 'value' => 3]]]);
        $this->asStaff($this->admin(), 'post', "v1/offers/{$v2['id']}/publish");
        $d = $this->json($this->act($l['licence_ref'], 'renew', ['version' => 1]))['data'];
        $this->assertSame(2, $d['offer']['version']);
        $this->assertSame('Books — v2', $d['offer']['label']);
        $this->assertSame(3, $d['entitlements'][0]['value']);
        $this->assertEquals(['from' => 1, 'to' => 2], $d['events'][0]['changes']['offer_version']);
    }

    public function testExpiryJobAndRenewFromExpired(): void
    {
        $l = $this->issue(42, ['starts_at' => '2024-01-01']);
        $this->assertSame('expired', $l['status']['code'], 'shown expired before the job runs');
        $this->assertSame('active', $l['stored_status']);
        $this->assertSame(1, Services::licences()->expireDue());
        $this->assertSame(0, Services::licences()->expireDue());
        $d = $this->json($this->asStaff($this->admin(), 'get', "v1/licences/{$l['licence_ref']}"))['data'];
        $this->assertSame('expired', $d['stored_status']);
        $this->assertSame('expired', $d['events'][0]['type']['code']);
        $this->assertSame('system', $d['events'][0]['actor']['kind']);
        $this->assertSame(1, $this->db()->table('engage_audit_logs')->where('event', 'licence.expire')->where('actor_email', 'system:engage')->countAllResults());

        // Renewing an expired licence starts the new term now.
        $d = $this->json($this->act($l['licence_ref'], 'renew', ['version' => $d['version']]))['data'];
        $this->assertSame('active', $d['status']['code']);
        $this->assertGreaterThan(time() + 360 * 86400, strtotime($d['term']['ends_at']));
        $this->assertEquals(['from' => 'expired', 'to' => 'active'], $d['events'][0]['changes']['status']);
    }

    public function testSeatsAndSeatAssignments(): void
    {
        $l = $this->issue(); // offer gives 3 seats
        $ref = $l['licence_ref'];
        $d = $this->json($this->act($ref, 'seat-assignments', ['version' => 1, 'member_ref' => 'uuid-a', 'member_label' => 'Asha']))['data'];
        $this->assertSame(1, $d['seats']['assigned']);
        $this->assertSame(409, $this->code($this->act($ref, 'seat-assignments', ['version' => 2, 'member_ref' => 'uuid-a'])), 'same member twice');
        $this->act($ref, 'seat-assignments', ['version' => 2, 'member_ref' => 'uuid-b']);
        $d = $this->json($this->act($ref, 'seat-assignments', ['version' => 3]))['data'];
        $this->assertSame(3, $d['seats']['assigned']);
        $r = $this->act($ref, 'seat-assignments', ['version' => 4]);
        $this->assertSame('no_free_seat', $this->json($r)['code']);

        $r = $this->act($ref, 'seats', ['version' => 4, 'seats_total' => 2]);
        $this->assertSame('seats_in_use', $this->json($r)['code']);
        $seatId = $d['seat_assignments'][0]['seat_id'];
        $d = $this->json($this->asStaff($this->admin(), 'post', "v1/licences/{$ref}/seat-assignments/{$seatId}/release", ['version' => 4]))['data'];
        $this->assertSame(2, $d['seats']['assigned']);
        $this->assertSame(409, $this->code($this->asStaff($this->admin(), 'post', "v1/licences/{$ref}/seat-assignments/{$seatId}/release", ['version' => 5])));
        $d = $this->json($this->act($ref, 'seats', ['version' => 5, 'seats_total' => 2, 'reason' => 'Downsized']))['data'];
        $this->assertSame(['total' => 2, 'assigned' => 2], $d['seats']);
        $this->assertSame('Seats changed from 3 to 2', $d['events'][0]['summary']);
        $d = $this->json($this->act($ref, 'seats', ['version' => 6, 'seats_total' => null]))['data'];
        $this->assertNull($d['seats']['total']);
        $types = array_count_values(array_column(array_column($d['events'], 'type'), 'code'));
        $this->assertSame(3, $types['seat_assigned']);
        $this->assertSame(1, $types['seat_released']);
        $this->assertSame(2, $types['seats_changed']);
    }

    public function testInvoiceRefs(): void
    {
        $l = $this->issue();
        $d = $this->json($this->act($l['licence_ref'], 'invoice-refs', ['version' => 1, 'invoice_ref' => 'SB/26-27/0042']))['data'];
        $this->assertSame(['SB/26-27/0042'], $d['invoice_refs']);
        $this->assertSame('Invoice SB/26-27/0042 linked', $d['events'][0]['summary']);
        $this->assertSame('already_linked', $this->json($this->act($l['licence_ref'], 'invoice-refs', ['version' => 2, 'invoice_ref' => 'SB/26-27/0042']))['code']);
        $this->assertSame(422, $this->code($this->act($l['licence_ref'], 'invoice-refs', ['version' => 2, 'invoice_ref' => '<script>'])));
    }

    public function testEventsAreAppendOnlyWithSeqPerCompany(): void
    {
        $a = $this->issue(42);
        $this->issue(77);
        $this->act($a['licence_ref'], 'suspend', ['version' => 1, 'reason' => 'x']);
        $db = $this->db();
        $seq = static fn (int $cmp) => array_map('intval', array_column($db->query('SELECT seq FROM engage_licence_events WHERE cmp_id = ? ORDER BY id', [$cmp])->getResultArray(), 'seq'));
        $this->assertSame([1, 2, 3], $seq(42));
        $this->assertSame([1, 2], $seq(77));
        foreach (['UPDATE engage_licence_events SET summary = \'x\'', 'DELETE FROM engage_licence_events', 'TRUNCATE engage_licence_events'] as $sql) {
            try {
                $db->query($sql);
                $this->fail('allowed: ' . $sql);
            } catch (\Throwable $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        // A seq sent by the writer is ignored: the trigger assigns it.
        $lic = $db->table('engage_licences')->where('cmp_id', 77)->get()->getRow();
        $db->query("INSERT INTO engage_licence_events (event_id, licence_id, licence_ref, cmp_id, seq, type, actor_kind, actor_display, summary, licence_version)
            VALUES ('lev_manual', ?, ?, 77, 999, 'invoice_linked', 'system', 'test', 'x', 1)", [$lic->id, $lic->licence_ref]);
        $this->assertSame([1, 2, 3], $seq(77));
    }

    public function testMembersCanReadButNotWriteLicences(): void
    {
        $l = $this->issue();
        $member = $this->staff(['member'], 'member@aicountly.org');
        $this->assertSame(200, $this->code($this->asStaff($member, 'get', "v1/licences/{$l['licence_ref']}")));
        $list = $this->json($this->asStaff($member, 'get', 'v1/licences', ['cmp_id' => 42]))['data']['items'];
        $this->assertCount(1, $list);
        $this->assertSame([], $this->json($this->asStaff($member, 'get', 'v1/licences', ['cmp_id' => 43]))['data']['items']);
        foreach (['suspend', 'revoke', 'renew', 'extend', 'seats', 'activate', 'reinstate', 'seat-assignments', 'invoice-refs'] as $a) {
            $this->assertSame(403, $this->code($this->asStaff($member, 'post', "v1/licences/{$l['licence_ref']}/{$a}", ['version' => 1, 'reason' => 'x'])), $a);
        }
        $this->assertSame(200, $this->code($this->asStaff($this->staff(['super_admin'], 'root@aicountly.org'), 'post', "v1/licences/{$l['licence_ref']}/suspend", ['version' => 1, 'reason' => 'x'])));
    }
}

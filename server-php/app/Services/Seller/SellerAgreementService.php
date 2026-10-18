<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use Config\Services;

/**
 * Seller agreement versions (Engage → Sellers → Agreement): draft → current → retired. Exactly one
 * version is current; publishing a draft retires the previous current version. Sellers must accept the
 * current version to finish activation; after a new version is published, existing sellers see
 * next_step "accept_agreement" (their status is not changed).
 */
class SellerAgreementService
{
    public function __construct(private ?SellerAccountService $accounts = null)
    {
        $this->accounts ??= Services::sellerAccounts();
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->accounts->db()->query(
            'SELECT v.*, (SELECT COUNT(*) FROM engage_seller_agreement_acceptances a WHERE a.agreement_version_id = v.id) AS acceptances
               FROM engage_seller_agreement_versions v ORDER BY v.id DESC'
        )->getResultArray();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['acceptances'] = (int) $r['acceptances'];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->accounts->db()->table('engage_seller_agreement_versions')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return array<string,string> */
    private static function validate(array $in, bool $creating): array
    {
        $e = [];
        if ($creating || array_key_exists('version_label', $in)) {
            if (! preg_match('/\A[A-Za-z0-9._\-]{1,32}\z/', (string) ($in['version_label'] ?? ''))) {
                $e['version_label'] = 'Version label: 1–32 letters, digits, . _ or - (e.g. 2026.1).';
            }
        }
        if ($creating || array_key_exists('title', $in)) {
            $t = trim((string) ($in['title'] ?? ''));
            if (mb_strlen($t) < 3 || mb_strlen($t) > 191) {
                $e['title'] = 'Give the agreement a title.';
            }
        }
        if ($creating || array_key_exists('body', $in)) {
            $b = trim((string) ($in['body'] ?? ''));
            if (mb_strlen($b) < 20 || mb_strlen($b) > 200000) {
                $e['body'] = 'Paste the full agreement text.';
            }
        }

        return $e;
    }

    /** @return array<string,mixed> */
    public function create(array $in, array $staff): array
    {
        $e = self::validate($in, true);
        if ($e === [] && $this->accounts->db()->table('engage_seller_agreement_versions')->where('version_label', $in['version_label'])->countAllResults() > 0) {
            $e['version_label'] = 'This version label is already used.';
        }
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the agreement.', 'details' => $e];
        }
        $this->accounts->db()->table('engage_seller_agreement_versions')->insert([
            'version_label' => $in['version_label'], 'title' => trim((string) $in['title']), 'body' => trim((string) $in['body']),
            'status' => 'draft', 'created_by' => (int) $staff['id'],
        ]);
        $id = (int) $this->accounts->db()->insertID();
        $this->audit('seller.agreement_created', $id, ['version_label' => $in['version_label']]);

        return ['status' => 201, 'data' => $this->find($id)];
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $in, array $staff): array
    {
        $v = $this->find($id);
        if (! $v) {
            return ['status' => 404, 'error' => 'Agreement version not found.'];
        }
        if ($v['status'] !== 'draft') {
            return ['status' => 409, 'error' => 'A published agreement cannot be edited; create a new version.'];
        }
        $e = self::validate($in, false);
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the agreement.', 'details' => $e];
        }
        $upd = array_intersect_key($in, array_flip(['version_label', 'title', 'body']));
        if ($upd !== []) {
            $upd['updated_at'] = Clock::now();
            $this->accounts->db()->table('engage_seller_agreement_versions')->where('id', $id)->update($upd);
        }

        return ['status' => 200, 'data' => $this->find($id)];
    }

    /** @return array<string,mixed> */
    public function publish(int $id, array $staff): array
    {
        $v = $this->find($id);
        if (! $v) {
            return ['status' => 404, 'error' => 'Agreement version not found.'];
        }
        if ($v['status'] !== 'draft') {
            return ['status' => 409, 'error' => 'Only a draft can be published.'];
        }
        $db = $this->accounts->db();
        $db->transStart();
        $db->query("UPDATE engage_seller_agreement_versions SET status = 'retired', retired_at = NOW(), updated_at = NOW() WHERE status = 'current'");
        $db->table('engage_seller_agreement_versions')->where('id', $id)->update([
            'status' => 'current', 'published_at' => Clock::now(), 'published_by' => (int) $staff['id'], 'updated_at' => Clock::now(),
        ]);
        $db->transComplete();
        $this->audit('seller.agreement_published', $id, ['version_label' => $v['version_label']]);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'seller_agreement', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}

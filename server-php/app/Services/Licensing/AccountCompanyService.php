<?php
declare(strict_types=1);

namespace App\Services\Licensing;

use App\Libraries\Licensing\LicensingSupport as S;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Engage account ↔ Manage company (cmp_id), engage_account_companies.
 *
 * Staff map an account to a Manage company id after checking it themselves (Engage has no Manage client yet, so
 * the mapping is recorded as "verified manually by <staff>"). A cmp_id belongs to at most one account at a time;
 * an account may hold several companies. Licences are issued only to a company mapped to the licence's account.
 * Unlinking is refused while that company still has a live (issued / active / suspended) licence of the account.
 * The mapping is a staff aid only: Manage reads are filtered by cmp_id and never depend on it.
 */
class AccountCompanyService
{
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    public static function view(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'account_id'          => (int) $r['account_id'],
            'account_name'        => $r['account_name'] ?? null,
            'cmp_id'              => (int) $r['cmp_id'],
            'company_name'        => $r['company_name'],
            'status'              => $r['status'],
            'verification_method' => $r['verification_method'],
            'verified_by_display' => $r['verified_by_display'],
            'verified_at'         => S::iso($r['verified_at']),
            'verification_note'   => $r['verification_note'],
            'unlinked_at'         => S::iso($r['unlinked_at']),
            'unlink_reason'       => $r['unlink_reason'],
            'licences_count'      => isset($r['licences_count']) ? (int) $r['licences_count'] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $where = [];
        $binds = [];
        if (! empty($q['account_id'])) {
            $where[] = 'm.account_id = ?';
            $binds[] = (int) $q['account_id'];
        }
        if (! empty($q['cmp_id'])) {
            $where[] = 'm.cmp_id = ?';
            $binds[] = (int) $q['cmp_id'];
        }
        if (! empty($q['status'])) {
            $where[] = 'm.status = ?';
            $binds[] = (string) $q['status'];
        }
        $sql = 'SELECT m.*, a.name AS account_name,
                (SELECT COUNT(*) FROM engage_licences l WHERE l.cmp_id = m.cmp_id AND l.account_id = m.account_id) AS licences_count
            FROM engage_account_companies m JOIN engage_accounts a ON a.id = m.account_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY (m.status = 'active') DESC, m.verified_at DESC, m.id DESC LIMIT 500";

        return array_map([self::class, 'view'], $this->db()->query($sql, $binds)->getResultArray());
    }

    public function activeMapping(int $accountId, int $cmpId): ?array
    {
        return $this->db()->table('engage_account_companies')
            ->where('account_id', $accountId)->where('cmp_id', $cmpId)->where('status', 'active')
            ->get()->getRowArray() ?: null;
    }

    public function link(array $in, array $staff): array
    {
        $e = [];
        $accountId = (int) ($in['account_id'] ?? 0);
        $cmp = $in['cmp_id'] ?? null;
        $cmpId = is_int($cmp) || (is_string($cmp) && preg_match('/\A[1-9]\d{0,17}\z/', $cmp)) ? (int) $cmp : 0;
        if ($accountId < 1 || ! $this->db()->table('engage_accounts')->where('id', $accountId)->countAllResults()) {
            $e['account_id'] = 'Choose an existing account.';
        }
        if ($cmpId < 1) {
            $e['cmp_id'] = 'cmp_id must be the numeric Manage company id.';
        }
        $name = trim((string) ($in['company_name'] ?? ''));
        if (mb_strlen($name) > 255) {
            $e['company_name'] = 'Company name is too long.';
        }
        $note = trim((string) ($in['verification_note'] ?? ''));
        if ($note === '') {
            $e['verification_note'] = 'Say how you checked that this company belongs to the account (e.g. confirmed with the owner in Manage).';
        }
        if ($e !== []) {
            return ['status' => 422, 'error' => 'Please correct the mapping.', 'details' => $e];
        }
        $existing = $this->db()->table('engage_account_companies')->where('cmp_id', $cmpId)->where('status', 'active')->get()->getRowArray();
        if ($existing) {
            return (int) $existing['account_id'] === $accountId
                ? ['status' => 409, 'error' => "Company {$cmpId} is already mapped to this account."]
                : ['status' => 409, 'error' => "Company {$cmpId} is mapped to another account (#{$existing['account_id']}). Unlink it there first."];
        }
        $who = (string) ($staff['email'] ?? ('staff #' . (int) $staff['id']));
        try {
            $this->db()->table('engage_account_companies')->insert([
                'account_id' => $accountId, 'cmp_id' => $cmpId, 'company_name' => $name !== '' ? $name : null,
                'status' => 'active', 'verification_method' => 'manual', 'verified_by' => (int) $staff['id'],
                'verified_by_display' => 'verified manually by ' . $who, 'verification_note' => $note,
            ]);
        } catch (\Throwable $ex) {
            // Unique (cmp_id) WHERE active — a concurrent link won.
            return ['status' => 409, 'error' => "Company {$cmpId} was just mapped by someone else. Reload."];
        }
        $id = (int) $this->db()->insertID();
        $this->audit('licence.company_linked', $id, ['account_id' => $accountId, 'cmp_id' => $cmpId]);

        return ['status' => 201, 'data' => $this->viewById($id)];
    }

    public function unlink(int $id, string $reason, array $staff): array
    {
        $m = $this->db()->table('engage_account_companies')->where('id', $id)->get()->getRowArray();
        if (! $m) {
            return ['status' => 404, 'error' => 'Mapping not found.'];
        }
        if ($m['status'] !== 'active') {
            return ['status' => 409, 'error' => 'This mapping is already unlinked.'];
        }
        if (trim($reason) === '') {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Required.']];
        }
        $live = $this->db()->table('engage_licences')->where('cmp_id', (int) $m['cmp_id'])->where('account_id', (int) $m['account_id'])
            ->whereIn('status', ['issued', 'active', 'suspended'])->countAllResults();
        if ($live > 0) {
            return ['status' => 409, 'error' => "Company {$m['cmp_id']} still has {$live} live licence(s) of this account. Revoke or let them expire first."];
        }
        $this->db()->query("UPDATE engage_account_companies SET status = 'unlinked', unlinked_at = NOW(), unlinked_by = ?, unlink_reason = ?, updated_at = NOW() WHERE id = ?",
            [(int) $staff['id'], trim($reason), $id]);
        $this->audit('licence.company_unlinked', $id, ['account_id' => (int) $m['account_id'], 'cmp_id' => (int) $m['cmp_id'], 'reason' => trim($reason)]);

        return ['status' => 200, 'data' => $this->viewById($id)];
    }

    private function viewById(int $id): array
    {
        $r = $this->db()->query('SELECT m.*, a.name AS account_name FROM engage_account_companies m JOIN engage_accounts a ON a.id = m.account_id WHERE m.id = ?', [$id])->getRowArray();

        return self::view($r);
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'account_company', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}

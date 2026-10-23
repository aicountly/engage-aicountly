<?php
declare(strict_types=1);

namespace App\Services\Seller;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\Money;
use Config\Services;

/**
 * Staff administration of sellers (Engage → Sellers): suspend / reactivate / terminate (with reason),
 * resend activation, reset password, unlock. Role gates are in Routes.php (super_admin | seller_manager).
 */
class SellerAdminService
{
    public function __construct(private ?SellerAccountService $accounts = null, private ?SellerMailer $mailer = null)
    {
        $this->accounts ??= Services::sellerAccounts();
        $this->mailer   ??= Services::sellerMailer();
    }

    /** Staff view of a seller row: no password hash. @param array<string,mixed> $s */
    public static function staffView(array $s): array
    {
        return [
            'id'                    => (int) $s['id'],
            'seller_code'           => $s['seller_code'],
            'name'                  => $s['name'],
            'email'                 => $s['email'],
            'mobile'                => $s['mobile'],
            'organization'          => $s['organization'],
            'city'                  => $s['city'],
            'state'                 => $s['state'],
            'status'                => $s['status'],
            'kyc_status'            => $s['kyc_status'],
            'status_reason'         => $s['status_reason'],
            'status_changed_at'     => Clock::iso($s['status_changed_at'] ?? null),
            'has_password'          => ! empty($s['password_hash']),
            'activated_at'          => Clock::iso($s['activated_at'] ?? null),
            'failed_attempts'       => (int) ($s['failed_attempts'] ?? 0),
            'locked_until'          => Clock::iso($s['locked_until'] ?? null),
            'is_locked'             => SellerAccountService::isLocked($s),
            'last_login_at'         => Clock::iso($s['last_login_at'] ?? null),
            'agreement_version_id'  => $s['agreement_version_id'] !== null ? (int) $s['agreement_version_id'] : null,
            'agreement_accepted_at' => Clock::iso($s['agreement_accepted_at'] ?? null),
            'application_id'        => $s['application_id'] !== null ? (int) $s['application_id'] : null,
            'lead_id'               => $s['lead_id'] !== null ? (int) $s['lead_id'] : null,
            'created_at'            => Clock::iso($s['created_at'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    public function list(array $q, int $limit, int $offset): array
    {
        $b = $this->accounts->db()->table('engage_sellers');
        if (! empty($q['status'])) {
            $b->where('status', (string) $q['status']);
        }
        if (! empty($q['kyc_status'])) {
            $b->where('kyc_status', (string) $q['kyc_status']);
        }
        if (! empty($q['q'])) {
            $term = (string) $q['q'];
            $b->groupStart()->like('name', $term, 'both', null, true)->orLike('email', $term, 'both', null, true)
                ->orLike('seller_code', $term, 'both', null, true)->orLike('organization', $term, 'both', null, true)->groupEnd();
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['items' => array_map([self::class, 'staffView'], $rows), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        $s = $this->accounts->find($id);
        if (! $s) {
            return null;
        }
        $db = $this->accounts->db();
        $view = self::staffView($s);
        $view['kyc'] = Services::sellerKyc()->staffView($id);
        $view['activation_pending'] = (bool) $db->query(
            "SELECT 1 FROM engage_seller_tokens WHERE seller_id = ? AND purpose = 'activation' AND used_at IS NULL AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1", [$id]
        )->getRow();
        $view['active_sessions'] = (int) $db->query(
            'SELECT COUNT(*) AS n FROM engage_seller_sessions WHERE seller_id = ? AND revoked_at IS NULL AND expires_at > NOW()', [$id]
        )->getRow()->n;
        $view['agreements'] = $db->query(
            'SELECT a.agreement_version_id, v.version_label, a.accepted_at, a.ip_address FROM engage_seller_agreement_acceptances a
               JOIN engage_seller_agreement_versions v ON v.id = a.agreement_version_id WHERE a.seller_id = ? ORDER BY a.accepted_at DESC', [$id]
        )->getResultArray();
        $view['leads'] = $db->query(
            'SELECT id, lead_code, name, organization, stage, sales_status, seller_attribution, seller_protection_expires_at, created_at
               FROM engage_leads WHERE seller_id = ? ORDER BY id DESC LIMIT 200', [$id]
        )->getResultArray();
        $totals = [];
        foreach ($db->query('SELECT status, SUM(amount) AS amount, COUNT(*) AS n FROM engage_commission_entries WHERE seller_id = ? GROUP BY status', [$id])->getResultArray() as $r) {
            $totals[$r['status']] = ['amount' => Money::format(Money::toPaise((string) $r['amount'])), 'count' => (int) $r['n']];
        }
        $view['commission_totals'] = $totals;
        $view['application'] = $s['application_id'] ? $db->table('engage_seller_applications')->where('id', (int) $s['application_id'])->get()->getRowArray() : null;

        return $view;
    }

    /**
     * Issue an activation or password-reset link and email it when a mailer is configured.
     * When nothing was sent, the link is returned here — once — for the staff member to pass on.
     *
     * @return array{email_sent:bool, channel:?string, reason:?string, expires_at:string, link:?string, message:string}
     */
    public function deliverLink(array $seller, string $purpose, ?int $staffId, bool $resend = false): array
    {
        $t = $this->accounts->issueToken((int) $seller['id'], $purpose, $staffId, '', false,
            $purpose === 'password_reset' ? SellerAccountService::STAFF_RESET_SECONDS : null);
        $url = $purpose === 'activation' ? SellerAccountService::activationUrl($t['token']) : SellerAccountService::resetUrl($t['token']);
        $vars = $purpose === 'activation'
            ? ['name' => $seller['name'], 'seller_code' => $seller['seller_code'], 'activation_url' => $url, 'expires_at' => $t['expires_at']]
            : ['name' => $seller['name'], 'reset_url' => $url, 'expires_at' => $t['expires_at']];
        $mail = $this->mailer->send($purpose === 'activation' ? 'seller_activation' : 'seller_password_reset',
            (string) $seller['email'], (string) $seller['name'], $vars, "seller-{$purpose}-{$t['id']}", ['resend' => $resend]);
        $this->accounts->setTokenDelivery($t['id'], $mail['sent'] ? 'email:' . $mail['channel'] : 'shown_to_staff');
        $what = $purpose === 'activation' ? 'activation' : 'password reset';

        return [
            'email_sent' => $mail['sent'],
            'channel'    => $mail['channel'],
            'reason'     => $mail['reason'],
            'expires_at' => $t['expires_at'],
            'link'       => $mail['sent'] ? null : $url,
            'message'    => $mail['sent']
                ? "The {$what} link was emailed to {$seller['email']}."
                : "No email was sent (" . ($mail['reason'] ?? 'not_configured') . "). Copy this {$what} link now and send it to {$seller['email']} yourself — it is shown only once and expires {$t['expires_at']}.",
        ];
    }

    /** @return array<string,mixed> */
    public function suspend(int $id, array $staff, string $reason): array
    {
        return $this->transition($id, $staff, $reason, ['onboarding', 'kyc_pending', 'active'], 'suspended', 'seller.suspended');
    }

    /** @return array<string,mixed> */
    public function reactivate(int $id, array $staff, string $reason): array
    {
        $s = $this->accounts->find($id);
        if (! $s) {
            return ['status' => 404, 'error' => 'Seller not found.'];
        }

        return $this->transition($id, $staff, $reason, ['suspended'], $this->accounts->settledStatus($s), 'seller.reactivated');
    }

    /** @return array<string,mixed> */
    public function terminate(int $id, array $staff, string $reason): array
    {
        return $this->transition($id, $staff, $reason, ['onboarding', 'kyc_pending', 'active', 'suspended'], 'terminated', 'seller.terminated');
    }

    /** @param list<string> $from @return array<string,mixed> */
    private function transition(int $id, array $staff, string $reason, array $from, string $to, string $event): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            return ['status' => 422, 'error' => 'A reason is required.', 'details' => ['reason' => 'Give a reason.']];
        }
        $s = $this->accounts->find($id);
        if (! $s) {
            return ['status' => 404, 'error' => 'Seller not found.'];
        }
        if (! in_array($s['status'], $from, true)) {
            return ['status' => 409, 'error' => "A {$s['status']} seller cannot be moved to {$to}."];
        }
        $this->accounts->changeStatus($id, $to, $reason, (int) $staff['id']);
        $this->audit($event, $s, ['from' => $s['status'], 'to' => $to, 'reason' => $reason]);

        return ['status' => 200, 'data' => self::staffView($this->accounts->find($id))];
    }

    /** @return array<string,mixed> */
    public function resendActivation(int $id, array $staff): array
    {
        $s = $this->accounts->find($id);
        if (! $s) {
            return ['status' => 404, 'error' => 'Seller not found.'];
        }
        if ($s['status'] !== 'onboarding' || ! empty($s['password_hash'])) {
            return ['status' => 409, 'error' => 'Only a seller who has not set a password yet can be sent an activation link. Use reset password instead.'];
        }
        // A resend: the first copy may not have arrived, so the fallback provider is tried first.
        $d = $this->deliverLink($s, 'activation', (int) $staff['id'], true);
        $this->audit('seller.activation_resent', $s, ['email_sent' => $d['email_sent']]);

        return ['status' => 200, 'data' => ['seller' => self::staffView($s), 'activation' => $d]];
    }

    /** @return array<string,mixed> */
    public function resetPassword(int $id, array $staff): array
    {
        $s = $this->accounts->find($id);
        if (! $s) {
            return ['status' => 404, 'error' => 'Seller not found.'];
        }
        if (! in_array($s['status'], SellerAccountService::LOGIN_STATUSES, true)) {
            return ['status' => 409, 'error' => "A {$s['status']} seller cannot sign in; reactivate first."];
        }
        if (empty($s['password_hash'])) {
            return ['status' => 409, 'error' => 'This seller has not set a password yet. Use resend activation instead.'];
        }
        $revoked = $this->accounts->revokeAllSessions($id, 'staff_reset');
        $d = $this->deliverLink($s, 'password_reset', (int) $staff['id']);
        $this->audit('seller.password_reset_by_staff', $s, ['email_sent' => $d['email_sent'], 'sessions_revoked' => $revoked]);

        return ['status' => 200, 'data' => ['seller' => self::staffView($s), 'reset' => $d, 'sessions_revoked' => $revoked]];
    }

    /** @return array<string,mixed> */
    public function unlock(int $id, array $staff): array
    {
        $s = $this->accounts->find($id);
        if (! $s) {
            return ['status' => 404, 'error' => 'Seller not found.'];
        }
        $this->accounts->db()->table('engage_sellers')->where('id', $id)->update(['failed_attempts' => 0, 'locked_until' => null, 'updated_at' => Clock::now()]);
        $this->audit('seller.unlocked', $s, []);

        return ['status' => 200, 'data' => self::staffView($this->accounts->find($id))];
    }

    private function audit(string $event, array $s, array $meta): void
    {
        Services::auditService()->log($event, [
            'subject_kind' => 'seller', 'subject_id' => $s['id'],
            'metadata' => array_merge(['seller_code' => $s['seller_code']], $meta), 'fanout_console' => true,
        ]);
    }
}

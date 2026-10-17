<?php

namespace App\Controllers\SellerPortal;

use App\Libraries\Seller\Clock;
use App\Libraries\Seller\Money;
use App\Models\LeadsModel;
use App\Models\SettingsModel;
use App\Services\Seller\SellerLeadService;
use Config\Services;

/**
 * Signed-in seller (portal key + X-Seller-Session):
 *   GET   me                     → {seller}
 *   PATCH me                     {name?, business_name?, mobile?, city?, state?} → {seller}
 *   POST  me/password            {current_password, new_password} → {session_token, expires_at} (all old sessions revoked)
 *   GET   dashboard
 *   GET   agreement              → {agreement: {version, title, body_text, published_at} | null, accepted, accepted_version, accepted_at}
 *   POST  agreement/acceptances  {version, accepted: true} → 201 {accepted_version, accepted_at, seller}
 *   GET   referral               → {code, links, protection_days, referred_leads}   (Engage extra)
 */
class AccountController extends BaseSellerPortalController
{
    public function me()
    {
        return $this->ok(['seller' => Services::sellerAccounts()->publicView($this->seller())]);
    }

    public function updateMe()
    {
        if ($r = $this->writeBlock(true)) {
            return $r;
        }
        $in = $this->input();
        $s = $this->seller();
        $acc = Services::sellerAccounts();
        $upd = [];
        $e = [];
        if (array_key_exists('name', $in)) {
            $v = trim((string) $in['name']);
            if (mb_strlen($v) < 2 || mb_strlen($v) > 191) {
                $e['name'] = 'Enter your full name.';
            }
            $upd['name'] = $v;
        }
        if (array_key_exists('business_name', $in)) {
            $v = $in['business_name'] === null ? '' : trim((string) $in['business_name']);
            if (mb_strlen($v) > 191) {
                $e['business_name'] = 'Keep this under 191 characters.';
            }
            $upd['organization'] = $v === '' ? null : $v;
        }
        if (array_key_exists('mobile', $in)) {
            $m = trim((string) $in['mobile']);
            $norm = LeadsModel::normaliseMobile($m);
            if ($norm === null || strlen(preg_replace('/\D+/', '', $m) ?? '') > 15) {
                $e['mobile'] = 'Enter a valid mobile number.';
            } elseif ($acc->db()->query("SELECT 1 FROM engage_sellers WHERE id <> ? AND RIGHT(regexp_replace(COALESCE(mobile, ''), '\\D', '', 'g'), 10) = ? LIMIT 1", [(int) $s['id'], $norm])->getRow()) {
                $e['mobile'] = 'This mobile number is used by another seller account.';
            }
            $upd['mobile'] = $m;
        }
        foreach (['city' => 96, 'state' => 96] as $f => $max) {
            if (array_key_exists($f, $in)) {
                $v = trim((string) $in[$f]);
                if (mb_strlen($v) > $max) {
                    $e[$f] = "Keep this under {$max} characters.";
                }
                $upd[$f] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('email', $in) && strcasecmp(trim((string) $in['email']), (string) $s['email']) !== 0) {
            $e['email'] = 'Contact AICOUNTLY to change your email address.';
        }
        if ($e !== []) {
            return $this->failCode(422, 'validation_failed', 'Please correct the highlighted fields.', $e);
        }
        if ($upd !== []) {
            $upd['updated_at'] = Clock::now();
            $acc->db()->table('engage_sellers')->where('id', (int) $s['id'])->update($upd);
            Services::sellerAuth()->audit('seller.profile_updated', $s, ['fields' => array_keys($upd)]);
        }

        return $this->ok(['seller' => $acc->publicView($acc->find((int) $s['id']))]);
    }

    public function password()
    {
        if ($r = $this->writeBlock(true)) {
            return $r;
        }
        $in = $this->input();

        return $this->result(Services::sellerAuth()->changePassword($this->seller(),
            (string) ($in['current_password'] ?? ''), (string) ($in['new_password'] ?? ''), $this->clientIp(), $this->clientUa()));
    }

    public function agreement()
    {
        return $this->ok(Services::sellerAuth()->agreementState($this->seller()));
    }

    public function acceptAgreement()
    {
        if ($r = $this->writeBlock(true)) {
            return $r;
        }
        $in = $this->input();

        return $this->result(Services::sellerAuth()->acceptAgreement($this->seller(), (string) ($in['version'] ?? ''),
            $in['accepted'] ?? null, $this->clientIp(), $this->clientUa()));
    }

    /** Monday–Sunday of the current week in IST. @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} */
    public static function istWeek(?int $now = null): array
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $d = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
        $start = $d->modify('monday this week')->setTime(0, 0);

        return [$start, $start->modify('+6 days')];
    }

    public function dashboard()
    {
        $s = $this->seller();
        $db = Services::sellerAccounts()->db();
        $registered = 0;
        $active = 0;
        $conversions = 0;
        foreach ($db->table('engage_leads')->select('stage, sales_status, seller_id, seller_protection_expires_at')->where('seller_id', (int) $s['id'])->get()->getResultArray() as $l) {
            $registered++;
            $st = SellerLeadService::sellerStatus($l);
            if ($st === 'converted') {
                $conversions++;
            } elseif (! in_array($st, ['lost', 'expired'], true)) {
                $active++;
            }
        }
        [$wStart, $wEnd] = self::istWeek();
        $sum = static fn (string $sql, array $b): int => Money::toPaise((string) ($db->query($sql, $b)->getRow()->s ?? '0') ?: '0');
        $week = $sum("SELECT COALESCE(SUM(amount), 0) AS s FROM engage_commission_entries WHERE seller_id = ? AND status <> 'reversed' AND accrued_at >= ? AND accrued_at < ?",
            [(int) $s['id'], Clock::at($wStart->getTimestamp()), Clock::at($wEnd->modify('+1 day')->getTimestamp())]);
        $pending = $sum("SELECT COALESCE(SUM(amount), 0) AS s FROM engage_commission_entries WHERE seller_id = ? AND status IN ('accrued','approved','in_payout')", [(int) $s['id']]);
        $paid = $sum("SELECT COALESCE(SUM(amount), 0) AS s FROM engage_commission_entries WHERE seller_id = ? AND status = 'paid'", [(int) $s['id']]);
        try {
            $threshold = Money::format(Money::toPaise((string) (new SettingsModel())->getSetting('seller_payout_min_threshold', '500.00')));
        } catch (\Throwable $e) {
            $threshold = '500.00';
        }
        $plan = Services::commissions()->planSummary();

        return $this->ok([
            'currency'           => 'INR',
            'week'               => ['start' => $wStart->format('Y-m-d'), 'end' => $wEnd->format('Y-m-d')],
            'leads_registered'   => $registered,
            'leads_active'       => $active,
            'conversions'        => $conversions,
            'earnings_this_week' => Money::format($week),
            'pending_amount'     => Money::format($pending),
            'paid_total'         => Money::format($paid),
            'next_payout_date'   => null, // weekly payouts are not enabled yet
            'payouts_enabled'    => false,
            'payout_threshold'   => $threshold,
            'commission_plan'    => ['published' => $plan['published'], 'name' => $plan['name'], 'version' => $plan['version']],
        ]);
    }

    public function referral()
    {
        $s = $this->seller();
        $acc = Services::sellerAccounts();
        $referred = (int) $acc->db()->table('engage_leads')->where('seller_id', (int) $s['id'])->where('seller_attribution', 'referral')->countAllResults();

        return $this->ok([
            'code'            => $s['seller_code'],
            'active'          => in_array($s['status'], SellerLeadService::ATTRIBUTABLE_STATUSES, true),
            'links'           => $acc::referralLinks((string) $s['seller_code']),
            'protection_days' => SellerLeadService::protectionDays(),
            'referred_leads'  => $referred,
        ]);
    }
}

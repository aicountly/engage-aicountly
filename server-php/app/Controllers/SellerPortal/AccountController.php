<?php

namespace App\Controllers\SellerPortal;

use App\Libraries\Seller\Clock;
use App\Services\Seller\SellerAuthService;
use App\Services\Seller\SellerLeadService;
use Config\Services;

/**
 * Signed-in seller (session):
 *   GET  me                 → seller (status, kyc_status, agreement, next_step, referral_code, payouts_enabled:false)
 *   GET  profile / PUT profile {mobile?, organization?, city?, state?}   (name / email change: ask AICOUNTLY)
 *   GET  agreement          → current agreement {id, version, title, body, published_at} | null (also without a session)
 *   POST agreement/accept   {agreement_version_id}
 *   GET  dashboard          → counts and totals
 *   GET  referral           → {code, links, protection_days, referred_leads}
 */
class AccountController extends BaseSellerPortalController
{
    public function me()
    {
        return $this->ok(Services::sellerAccounts()->publicView($this->seller()));
    }

    public function profile()
    {
        return $this->ok(Services::sellerAccounts()->publicView($this->seller()));
    }

    public function updateProfile()
    {
        $in = $this->input();
        $s = $this->seller();
        $upd = [];
        $e = [];
        if (array_key_exists('mobile', $in)) {
            $m = trim((string) $in['mobile']);
            if (\App\Models\LeadsModel::normaliseMobile($m) === null || strlen(preg_replace('/\D+/', '', $m) ?? '') > 15) {
                $e['mobile'] = 'Enter a valid mobile number.';
            }
            $upd['mobile'] = $m;
        }
        foreach (['organization' => 191, 'city' => 96, 'state' => 96] as $f => $max) {
            if (array_key_exists($f, $in)) {
                $v = trim((string) $in[$f]);
                if (mb_strlen($v) > $max) {
                    $e[$f] = "Keep this under {$max} characters.";
                }
                $upd[$f] = $v === '' ? null : $v;
            }
        }
        foreach (['name', 'email'] as $f) {
            if (array_key_exists($f, $in) && trim((string) $in[$f]) !== (string) $s[$f]) {
                $e[$f] = 'Contact AICOUNTLY to change your ' . $f . '.';
            }
        }
        if ($e !== []) {
            return $this->failCode(422, 'validation_failed', 'Please correct the highlighted fields.', $e);
        }
        if ($upd !== []) {
            $upd['updated_at'] = Clock::now();
            Services::sellerAccounts()->db()->table('engage_sellers')->where('id', (int) $s['id'])->update($upd);
            Services::sellerAuth()->audit('seller.profile_updated', $s, ['fields' => array_keys($upd)]);
        }

        return $this->ok(Services::sellerAccounts()->publicView(Services::sellerAccounts()->find((int) $s['id'])));
    }

    public function agreement()
    {
        $a = Services::sellerAccounts()->currentAgreement();

        return $this->ok(['agreement' => $a ? SellerAuthService::agreementView($a) : null]);
    }

    public function acceptAgreement()
    {
        $in = $this->input();

        return $this->result(Services::sellerAuth()->acceptAgreement($this->seller(), $in['agreement_version_id'] ?? null, $this->clientIp(), $this->clientUa()));
    }

    public function dashboard()
    {
        $s = $this->seller();
        $db = Services::sellerAccounts()->db();
        $counts = ['total' => 0, 'registered' => 0, 'in_progress' => 0, 'converted' => 0, 'lost' => 0, 'closed' => 0, 'protected' => 0];
        foreach ($db->table('engage_leads')->select('stage, sales_status, seller_id, seller_protection_expires_at')->where('seller_id', (int) $s['id'])->get()->getResultArray() as $l) {
            $counts['total']++;
            $counts[SellerLeadService::sellerStatus($l)]++;
            if (SellerLeadService::isProtected($l)) {
                $counts['protected']++;
            }
        }
        $c = Services::commissions()->sellerSummary((int) $s['id'], 5);

        return $this->ok([
            'seller'      => Services::sellerAccounts()->publicView($s),
            'leads'       => $counts,
            'commissions' => [
                'plan_published' => $c['plan_published'],
                'message'        => $c['message'],
                'currency'       => 'INR',
                'totals'         => $c['totals'],
                'recent'         => $c['items'],
            ],
            'kyc'         => ['status' => $s['kyc_status']],
            'payouts'     => ['enabled' => false, 'status' => 'payouts not enabled yet'],
        ]);
    }

    public function referral()
    {
        $s = $this->seller();
        $code = (string) $s['seller_code'];
        $web = rtrim((string) env('SELLER_REFERRAL_WEBSITE_URL', 'https://www.aicountly.com/'), '/') . '/';
        $app = rtrim((string) env('SELLER_REFERRAL_APP_URL', 'https://my.aicountly.com/'), '/') . '/';
        $referred = (int) Services::sellerAccounts()->db()->table('engage_leads')->where('seller_id', (int) $s['id'])
            ->where('seller_attribution', 'referral')->countAllResults();

        return $this->ok([
            'code'            => $code,
            'active'          => in_array($s['status'], SellerLeadService::ATTRIBUTABLE_STATUSES, true),
            'links'           => [
                'website' => $web . '?ref=' . rawurlencode($code),
                'app'     => $app . '?ref=' . rawurlencode($code),
            ],
            'protection_days' => SellerLeadService::protectionDays(),
            'referred_leads'  => $referred,
        ]);
    }
}

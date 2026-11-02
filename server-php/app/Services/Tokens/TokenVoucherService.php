<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Token vouchers: "this is worth N tokens", redeemed straight into a customer's wallet.
 *
 * NOT A COUPON, and the difference is the whole reason this exists. A coupon
 * (docs/COUPONS.md) is a discount RULE applied to a price at the moment of sale: it has no
 * balance, it cannot take a price to zero (`net_paise >= 100`), and there is nothing left
 * over to roll back, because nothing was ever held. A voucher is a VALUE: it has a balance,
 * it is spent rather than applied, it needs no sale to exist, and what is not used is either
 * kept for next time or forfeited — `rollback` says which.
 *
 * A voucher is a FREE SUPPLY. No money changes hands, so no invoice is raised and no GST is
 * charged: issuing one is a promotional cost, not revenue. That is also why a voucher, not a
 * 100%-off coupon, is the right instrument for "here is Rs 1,000 of credit" — a zero-rupee
 * invoice is both meaningless and refused by Finance (`total_paise > 0`).
 *
 * Tokens are never handed over inside this call. A redemption writes a row to the grant
 * outbox and a worker pushes it to Pulse, so a customer's credit does not depend on Pulse
 * being reachable at that instant, and a failed push can be retried without granting twice.
 */
class TokenVoucherService
{
    public const STATUSES = ['draft', 'active', 'paused', 'expired', 'exhausted', 'retired'];

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array<string,mixed>> */
    public function list(array $q = []): array
    {
        $b = $this->db()->table('engage_token_vouchers v');
        if (($q['status'] ?? '') !== '') {
            $b->where('v.status', (string) $q['status']);
        }
        $rows = $b->orderBy('v.id', 'DESC')->get()->getResultArray();

        return array_map(fn (array $r): array => $this->view($r), $rows);
    }

    public function find(int $id): ?array
    {
        $r = $this->db()->table('engage_token_vouchers')->where('id', $id)->get()->getRowArray();

        return $r === null ? null : $this->view($r);
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * A draft voucher. Value may be given in tokens or in rupees; rupees are converted at
     * TODAY's token price and the rate is frozen onto the row, because a voucher handed out
     * as "Rs 1,000 of credit" must stay worth that many tokens even if the price changes
     * before someone redeems it.
     */
    public function create(array $in, array $staff): array
    {
        $rates = new TokenRateService($this->db);
        $pricing = $rates->pricing();

        $errors = [];
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'A short name for this voucher, up to 120 characters.';
        }

        $tokens = 0;
        $valuePaise = null;
        if (isset($in['value_rupees']) && $in['value_rupees'] !== '') {
            $valuePaise = (int) round(((float) $in['value_rupees']) * 100);
            if ($valuePaise < 1) {
                $errors['value_rupees'] = 'More than nothing.';
            } else {
                $tokens = $rates->tokensForPaise($valuePaise);
            }
        } elseif (isset($in['tokens']) && $in['tokens'] !== '') {
            $tokens = (int) $in['tokens'];
        } else {
            $errors['tokens'] = 'How much this voucher is worth, in tokens or in rupees.';
        }
        if ($errors === [] && ($tokens < 1 || $tokens > 100_000_000_000)) {
            $errors['tokens'] = 'Between 1 and 100,000,000,000 tokens.';
        }

        $kind = in_array($in['code_kind'] ?? 'single_use', ['single_use', 'shared'], true) ? $in['code_kind'] : 'single_use';
        $rollback = in_array($in['rollback'] ?? 'no_rollback', ['no_rollback', 'keep_balance'], true) ? $in['rollback'] : 'no_rollback';
        if ($rollback === 'keep_balance' && $kind === 'shared') {
            $errors['rollback'] = 'Only a single-use voucher can keep a balance: a shared one would be a single pot drained first-come-first-served.';
        }

        $min = isset($in['min_tokens']) && $in['min_tokens'] !== '' ? (int) $in['min_tokens'] : null;
        if ($min !== null && ($min < 1 || ($tokens > 0 && $min > $tokens))) {
            $errors['min_tokens'] = 'At least 1 and no more than the voucher is worth.';
        }

        $from = trim((string) ($in['valid_from'] ?? ''));
        $to = trim((string) ($in['valid_to'] ?? ''));
        foreach (['valid_from' => $from, 'valid_to' => $to] as $f => $v) {
            if ($v !== '' && strtotime($v) === false) {
                $errors[$f] = 'A date, or blank.';
            }
        }
        if ($errors !== []) {
            return self::fail(422, 'validation_failed', 'Please correct the voucher.', $errors);
        }

        $db = $this->db();
        $ref = 'TKV-' . str_pad((string) $db->query("SELECT nextval('engage_token_voucher_ref_seq') AS n")->getRow()->n, 6, '0', STR_PAD_LEFT);
        $db->table('engage_token_vouchers')->insert([
            'voucher_ref'                => $ref,
            'label'                      => $label,
            'description'                => trim((string) ($in['description'] ?? '')) ?: null,
            'tokens'                     => $tokens,
            'issued_value_paise'         => $valuePaise,
            'price_paise_per_1000'       => $valuePaise !== null ? $pricing['paise_per_1000_tokens'] : null,
            'code_kind'                  => $kind,
            'rollback'                   => $rollback,
            'min_tokens'                 => $min,
            'valid_from'                 => $from === '' ? null : date('Y-m-d H:i:sP', (int) strtotime($from)),
            'valid_to'                   => $to === '' ? null : date('Y-m-d H:i:sP', (int) strtotime($to)),
            'max_redemptions_total'      => isset($in['max_redemptions_total']) && $in['max_redemptions_total'] !== '' ? (int) $in['max_redemptions_total'] : null,
            'max_redemptions_per_holder' => isset($in['max_redemptions_per_holder']) && $in['max_redemptions_per_holder'] !== '' ? (int) $in['max_redemptions_per_holder'] : null,
            'campaign_ref'               => trim((string) ($in['campaign_ref'] ?? '')) ?: null,
            'status'                     => 'draft',
            'created_by'                 => (int) ($staff['id'] ?? 0) ?: null,
        ]);
        $id = (int) $db->insertID();
        $this->audit('token_voucher.create', $id, ['voucher_ref' => $ref, 'tokens' => $tokens, 'rollback' => $rollback]);

        return ['status' => 201, 'data' => $this->find($id)];
    }

    /** Add a code to a draft, or generate a batch of them. */
    public function addCodes(int $id, array $in, array $staff): array
    {
        $v = $this->db()->table('engage_token_vouchers')->where('id', $id)->get()->getRowArray();
        if ($v === null) {
            return self::fail(404, 'not_found', 'That voucher does not exist.');
        }
        if ($v['status'] !== 'draft') {
            return self::fail(409, 'not_draft', 'Add codes before the voucher is activated.');
        }

        $codes = [];
        $explicit = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['code'] ?? '')));
        if ($explicit !== '') {
            if (preg_match('/\A[A-Z0-9]{4,32}\z/', $explicit) !== 1) {
                return self::fail(422, 'validation_failed', 'A code is 4 to 32 letters and digits.', ['code' => 'Letters and digits only.']);
            }
            $codes[] = $explicit;
        } else {
            $count = max(1, min(5000, (int) ($in['count'] ?? 1)));
            for ($i = 0; $i < $count; $i++) {
                $codes[] = self::randomCode();
            }
        }

        $made = 0;
        foreach ($codes as $code) {
            try {
                $this->db()->table('engage_token_voucher_codes')->insert([
                    'voucher_id'  => $id,
                    'code'        => $code,
                    'kind'        => $v['code_kind'],
                    'status'      => 'available',
                    'tokens_left' => (int) $v['tokens'],
                    'batch_ref'   => trim((string) ($in['batch_ref'] ?? '')) ?: null,
                    'created_by'  => (int) ($staff['id'] ?? 0) ?: null,
                ]);
                $made++;
            } catch (\Throwable $e) {
                // A collision on the random code is not worth failing the batch for.
                if ($explicit !== '') {
                    return self::fail(409, 'code_taken', 'That code is already in use.');
                }
            }
        }
        $this->audit('token_voucher.codes', $id, ['added' => $made]);

        return ['status' => 201, 'data' => ['added' => $made] + (count($codes) === 1 ? ['code' => $codes[0]] : [])];
    }

    /** Four eyes, exactly as a coupon: whoever wrote it cannot approve it. */
    public function activate(int $id, array $staff): array
    {
        $db = $this->db();
        $v = $db->table('engage_token_vouchers')->where('id', $id)->get()->getRowArray();
        if ($v === null) {
            return self::fail(404, 'not_found', 'That voucher does not exist.');
        }
        if ($v['status'] === 'active') {
            return ['status' => 200, 'data' => $this->find($id)];
        }
        if ($v['status'] !== 'draft') {
            return self::fail(409, 'not_draft', 'Only a draft can be activated.');
        }
        $roles = (array) ($staff['roles'] ?? []);
        $me = (int) ($staff['id'] ?? 0);
        if (! in_array('super_admin', $roles, true) && (int) $v['created_by'] === $me) {
            return self::fail(409, 'four_eyes', 'You created this voucher — another sales manager or an administrator must approve it. It gives away real value.');
        }
        $codes = $db->table('engage_token_voucher_codes')->where('voucher_id', $id)->where('status', 'available')->countAllResults();
        if ($codes === 0) {
            return self::fail(409, 'no_codes', 'Add a code before activating.');
        }

        $db->table('engage_token_vouchers')->where('id', $id)->update([
            'status'      => 'active',
            'approved_by' => $me ?: null,
            'approved_at' => date('Y-m-d H:i:sP'),
            'version'     => (int) $v['version'] + 1,
            'updated_at'  => date('Y-m-d H:i:sP'),
        ]);
        $this->audit('token_voucher.activate', $id, ['voucher_ref' => $v['voucher_ref'], 'tokens' => (int) $v['tokens'], 'codes' => $codes]);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    // ------------------------------------------------------------- redemption

    /**
     * Redeem a code for a holder. The tokens are queued for Pulse, not pushed here.
     *
     * `want` lets a customer take only part of a keep_balance voucher. Left out, a
     * keep_balance voucher gives everything that is left (and so does a no_rollback one,
     * which has no other option).
     *
     * Everything happens under one advisory lock on the code, so two clicks cannot both
     * take the last tokens.
     */
    public function redeem(string $code, string $holderUuid, ?int $cmpId, ?int $want, string $source = 'manage'): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($code === '') {
            return self::fail(422, 'validation_failed', 'Enter a voucher code.');
        }
        if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $holderUuid) !== 1) {
            return self::fail(422, 'validation_failed', 'A licence holder is required.');
        }

        $db = $this->db();
        $db->transBegin();
        try {
            $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['engage-voucher:' . $code]);

            $row = $db->query(
                'SELECT c.*, v.id AS v_id, v.voucher_ref, v.status AS v_status, v.rollback, v.code_kind, v.tokens AS v_tokens,
                        v.min_tokens, v.valid_from, v.valid_to, v.max_redemptions_total, v.max_redemptions_per_holder, v.label
                   FROM engage_token_voucher_codes c JOIN engage_token_vouchers v ON v.id = c.voucher_id
                  WHERE c.code = ?',
                [$code]
            )->getRowArray();
            if ($row === null) {
                $db->transCommit();

                return self::fail(404, 'unknown_code', 'That voucher code was not recognised.');
            }

            if (($refusal = $this->refusal($db, $row, $holderUuid)) !== null) {
                $db->transCommit();

                return $refusal;
            }

            $left = (int) $row['tokens_left'];
            $take = $want !== null && $want > 0 ? min($want, $left) : $left;
            if ($row['rollback'] === 'no_rollback') {
                // Everything not taken now is forfeited. Taking less than the whole value is
                // therefore allowed but lossy, and the answer says exactly how much was lost.
                $forfeited = $left - $take;
                $remaining = 0;
            } else {
                $forfeited = 0;
                $remaining = $left - $take;
            }
            if ($row['min_tokens'] !== null && $take < (int) $row['min_tokens']) {
                $db->transCommit();

                return self::fail(409, 'below_minimum', 'This voucher is redeemed in at least ' . number_format((int) $row['min_tokens']) . ' tokens at a time.');
            }

            $grantRef = 'TKV:' . $row['voucher_ref'] . ':' . $code . ':' . (int) $db->query("SELECT nextval('engage_token_grant_ref_seq') AS n")->getRow()->n;

            $db->table('engage_token_voucher_redemptions')->insert([
                'voucher_id'       => (int) $row['v_id'],
                'code_id'          => (int) $row['id'],
                'code'             => $code,
                'holder_uuid'      => $holderUuid,
                'cmp_id'           => $cmpId,
                'tokens_granted'   => $take,
                'tokens_left'      => $remaining,
                'tokens_forfeited' => $forfeited,
                'grant_ref'        => $grantRef,
                'source'           => $source,
            ]);

            $db->table('engage_token_voucher_codes')->where('id', (int) $row['id'])->update([
                'tokens_left' => $remaining,
                'status'      => $remaining > 0 && $row['code_kind'] === 'single_use' ? 'available' : ($row['code_kind'] === 'single_use' ? 'redeemed' : 'available'),
                'updated_at'  => date('Y-m-d H:i:sP'),
            ]);

            // The outbox. Tokens reach Pulse from here, not from inside this request.
            $db->table('engage_token_grants')->insert([
                'grant_ref'   => $grantRef,
                'holder_uuid' => $holderUuid,
                'tokens'      => $take,
                'reason'      => 'Voucher ' . $row['voucher_ref'] . ' — ' . $row['label'],
                'origin'      => 'voucher',
                'origin_ref'  => $row['voucher_ref'],
                'status'      => 'pending',
            ]);

            // A voucher whose total uses are spent stops being offered.
            if ($row['max_redemptions_total'] !== null) {
                $used = (int) $db->query('SELECT COUNT(*) AS n FROM engage_token_voucher_redemptions WHERE voucher_id = ?', [(int) $row['v_id']])->getRowArray()['n'];
                if ($used >= (int) $row['max_redemptions_total']) {
                    $db->table('engage_token_vouchers')->where('id', (int) $row['v_id'])->update(['status' => 'exhausted', 'updated_at' => date('Y-m-d H:i:sP')]);
                }
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $this->audit('token_voucher.redeem', (int) $row['v_id'], [
            'code' => $code, 'holder' => $holderUuid, 'tokens' => $take, 'forfeited' => $forfeited,
        ]);

        return ['status' => 200, 'data' => [
            'voucher_ref'      => $row['voucher_ref'],
            'label'            => $row['label'],
            'tokens_granted'   => $take,
            'tokens_left'      => $remaining,
            'tokens_forfeited' => $forfeited,
            'grant_ref'        => $grantRef,
            // The tokens are queued, not yet in the wallet. Saying so is the difference
            // between a customer who waits a moment and a customer who thinks it failed.
            'delivery'         => 'queued',
        ]];
    }

    /** @return array<string,mixed>|null a refusal, or null when the code may be used */
    private function refusal($db, array $row, string $holderUuid): ?array
    {
        if ($row['v_status'] !== 'active') {
            return self::fail(409, 'not_active', 'That voucher is not active.');
        }
        if ($row['status'] === 'void') {
            return self::fail(409, 'code_void', 'That voucher code has been cancelled.');
        }
        if ((int) $row['tokens_left'] <= 0 || $row['status'] === 'redeemed') {
            return self::fail(409, 'already_redeemed', 'That voucher code has already been used.');
        }
        $now = time();
        if ($row['valid_from'] !== null && strtotime((string) $row['valid_from']) > $now) {
            return self::fail(409, 'not_yet_valid', 'That voucher cannot be used yet.');
        }
        if ($row['valid_to'] !== null && strtotime((string) $row['valid_to']) <= $now) {
            return self::fail(409, 'expired', 'That voucher has expired.');
        }
        if ($row['max_redemptions_total'] !== null) {
            $used = (int) $db->query('SELECT COUNT(*) AS n FROM engage_token_voucher_redemptions WHERE voucher_id = ?', [(int) $row['v_id']])->getRowArray()['n'];
            if ($used >= (int) $row['max_redemptions_total']) {
                return self::fail(409, 'exhausted', 'That voucher has been fully used.');
            }
        }
        if ($row['max_redemptions_per_holder'] !== null) {
            $mine = (int) $db->query(
                'SELECT COUNT(*) AS n FROM engage_token_voucher_redemptions WHERE voucher_id = ? AND holder_uuid = ?',
                [(int) $row['v_id'], $holderUuid]
            )->getRowArray()['n'];
            if ($mine >= (int) $row['max_redemptions_per_holder']) {
                return self::fail(409, 'already_used_by_holder', 'You have already used this voucher.');
            }
        }

        return null;
    }

    // --------------------------------------------------------------- helpers

    private function view(array $r): array
    {
        $db = $this->db();
        $id = (int) $r['id'];
        $stats = $db->query(
            'SELECT COUNT(*) AS uses, COALESCE(SUM(tokens_granted),0) AS granted, COALESCE(SUM(tokens_forfeited),0) AS forfeited
               FROM engage_token_voucher_redemptions WHERE voucher_id = ?',
            [$id]
        )->getRowArray();
        $codes = $db->query(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'available' AND tokens_left > 0) AS available
               FROM engage_token_voucher_codes WHERE voucher_id = ?",
            [$id]
        )->getRowArray();

        return [
            'id'                 => $id,
            'voucher_ref'        => (string) $r['voucher_ref'],
            'label'              => (string) $r['label'],
            'description'        => $r['description'],
            'tokens'             => (int) $r['tokens'],
            'issued_value_paise' => $r['issued_value_paise'] === null ? null : (int) $r['issued_value_paise'],
            'code_kind'          => (string) $r['code_kind'],
            'rollback'           => (string) $r['rollback'],
            'min_tokens'         => $r['min_tokens'] === null ? null : (int) $r['min_tokens'],
            'valid_from'         => $r['valid_from'],
            'valid_to'           => $r['valid_to'],
            'max_redemptions_total'      => $r['max_redemptions_total'] === null ? null : (int) $r['max_redemptions_total'],
            'max_redemptions_per_holder' => $r['max_redemptions_per_holder'] === null ? null : (int) $r['max_redemptions_per_holder'],
            'status'             => (string) $r['status'],
            'campaign_ref'       => $r['campaign_ref'],
            'stats'              => [
                'uses'            => (int) $stats['uses'],
                'tokens_granted'  => (int) $stats['granted'],
                'tokens_forfeited' => (int) $stats['forfeited'],
                'codes'           => (int) $codes['total'],
                'codes_available' => (int) $codes['available'],
            ],
            'created_at'         => $r['created_at'],
        ];
    }

    private static function randomCode(): string
    {
        // No vowels and no 0/O/1/I: a code is read aloud and typed in by hand.
        $alphabet = 'BCDFGHJKMNPQRSTVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private static function fail(int $status, string $code, string $msg, array $details = []): array
    {
        return ['status' => $status, 'code' => $code, 'error' => $msg] + ($details ? ['details' => $details] : []);
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'token_voucher', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}

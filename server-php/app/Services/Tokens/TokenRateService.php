<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use App\Models\SettingsModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Token conversion rates: the staff-facing lifecycle, and the card everything else reads.
 *
 * Four eyes to activate, and once a rate leaves draft its commercial columns are frozen by
 * engage_token_rates_guard — a rate that has priced real usage is evidence, so it is retired and
 * replaced, never edited. The same rules the coupon engine uses, for the same reason.
 *
 * The arithmetic itself is not here: it is in TokenRateCard, which holds no database handle, so
 * Engage, Pulse and a unit test all weigh tokens with one implementation.
 */
class TokenRateService
{
    public const STATUSES = ['draft', 'active', 'retired'];

    /** Settings keys this service owns, with the defaults migration 000040 seeds. */
    public const SETTING_PRICE = 'token_price_paise_per_1000';
    public const SETTING_GST = 'token_gst_rate';
    public const SETTING_SAC = 'token_sac';
    public const SETTING_TIERS = 'token_tier_fallback_bp';
    public const SETTING_UNCLASSIFIED = 'token_unclassified_tier';

    private const DEFAULT_TIERS = ['low' => 10000, 'medium' => 100000, 'high' => 250000];

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
        $b = $this->db()->table('engage_token_rates');
        if (($q['status'] ?? '') !== '') {
            $b->where('status', (string) $q['status']);
        }
        if (($q['provider_code'] ?? '') !== '') {
            $b->where('provider_code', (string) $q['provider_code']);
        }
        $rows = $b->orderBy('provider_code', 'ASC')->orderBy('model_key', 'ASC')->orderBy('id', 'DESC')->get()->getResultArray();

        return array_map(static fn (array $r): array => self::view($r), $rows);
    }

    public function find(int $id): ?array
    {
        $r = $this->db()->table('engage_token_rates')->where('id', $id)->get()->getRowArray();

        return $r === null ? null : self::view($r);
    }

    /** @return list<array<string,mixed>> the active rates, as the card and the push payload need them. */
    public function activeRates(): array
    {
        return $this->db()->query(
            "SELECT provider_code, model_key, multiplier_bp, rate_ref
               FROM engage_token_rates
              WHERE status = 'active'
                AND (effective_from IS NULL OR effective_from <= NOW())
                AND (effective_to   IS NULL OR effective_to   >  NOW())
              ORDER BY provider_code, model_key"
        )->getResultArray();
    }

    /** The rules, as one value. Everything that weighs a token goes through this. */
    public function card(): TokenRateCard
    {
        return TokenRateCard::fromRows($this->activeRates(), $this->tierFallback(), $this->unclassifiedTier());
    }

    /** @return array<string,int> */
    public function tierFallback(): array
    {
        $raw = (new SettingsModel())->getSetting(self::SETTING_TIERS, self::DEFAULT_TIERS);
        $out = [];
        foreach (is_array($raw) ? $raw : self::DEFAULT_TIERS as $tier => $bp) {
            $tier = strtolower((string) $tier);
            // A tier someone has emptied or typed badly falls back to its seeded value rather
            // than to 0, because a 0 would make that tier free and nothing would say so.
            $out[$tier] = (int) $bp > 0 ? (int) $bp : (self::DEFAULT_TIERS[$tier] ?? 100000);
        }

        return $out + self::DEFAULT_TIERS;
    }

    public function unclassifiedTier(): string
    {
        $t = strtolower((string) (new SettingsModel())->getSetting(self::SETTING_UNCLASSIFIED, 'medium'));

        return isset(self::DEFAULT_TIERS[$t]) ? $t : 'medium';
    }

    /** Paise per 1,000 tokens, exclusive of GST, plus the tax details an invoice needs. */
    public function pricing(): array
    {
        $s = new SettingsModel();

        return [
            'paise_per_1000_tokens' => max(0, (int) $s->getSetting(self::SETTING_PRICE, 100)),
            'gst_rate'              => (float) $s->getSetting(self::SETTING_GST, 18),
            'sac'                   => (string) $s->getSetting(self::SETTING_SAC, '997331'),
            'price_basis'           => 'exclusive',
            'currency'              => 'INR',
        ];
    }

    /**
     * Tokens for an amount, and the amount for tokens — the two directions a checkout needs.
     *
     * Integer paise throughout. Tokens are floored: a customer is never given a fraction of a
     * token they did not pay for, and the remainder is below a thousandth of a rupee.
     */
    public function tokensForPaise(int $paiseExclGst): int
    {
        $rate = $this->pricing()['paise_per_1000_tokens'];

        return $rate <= 0 ? 0 : intdiv(max(0, $paiseExclGst) * 1000, $rate);
    }

    public function paiseForTokens(int $tokens): int
    {
        $rate = $this->pricing()['paise_per_1000_tokens'];

        // Rounds up: selling 1,500 tokens at Rs 1 / 1,000 collects Rs 1.50, never Rs 1.49.
        return intdiv(max(0, $tokens) * $rate + 999, 1000);
    }

    // ------------------------------------------------------------- lifecycle

    public function create(array $in, array $staff): array
    {
        [$fields, $errors] = self::validate($in);
        if ($errors !== []) {
            return self::fail(422, 'validation_failed', 'Please correct the rate.', $errors);
        }
        $db = $this->db();
        $ref = 'TKR-' . str_pad((string) $db->query("SELECT nextval('engage_token_rate_ref_seq') AS n")->getRow()->n, 6, '0', STR_PAD_LEFT);
        $db->table('engage_token_rates')->insert($fields + [
            'rate_ref'   => $ref,
            'status'     => 'draft',
            'created_by' => (int) ($staff['id'] ?? 0) ?: null,
            'created_at' => date('Y-m-d H:i:sP'),
            'updated_at' => date('Y-m-d H:i:sP'),
        ]);
        $id = (int) $db->insertID();
        $this->audit('token_rate.create', $id, ['rate_ref' => $ref] + $fields);

        return ['status' => 201, 'data' => $this->find($id)];
    }

    public function update(int $id, array $in, array $staff): array
    {
        $row = $this->db()->table('engage_token_rates')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return self::fail(404, 'not_found', 'That rate does not exist.');
        }
        if ($row['status'] !== 'draft') {
            return self::fail(409, 'not_draft', 'An active or retired rate cannot be edited. Retire it and add a new one.');
        }
        [$fields, $errors] = self::validate($in);
        if ($errors !== []) {
            return self::fail(422, 'validation_failed', 'Please correct the rate.', $errors);
        }
        // Editing a colleague's draft and then approving it yourself is approving your own rule,
        // so every editor is remembered and checked at activation (same rule as coupons).
        $editors = self::editors($row);
        $me = (int) ($staff['id'] ?? 0);
        if ($me > 0 && ! in_array($me, $editors, true)) {
            $editors[] = $me;
        }
        $this->db()->table('engage_token_rates')->where('id', $id)->update($fields + [
            'edited_by'  => '{' . implode(',', $editors) . '}',
            'version'    => (int) $row['version'] + 1,
            'updated_at' => date('Y-m-d H:i:sP'),
        ]);
        $this->audit('token_rate.update', $id, $fields);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    public function activate(int $id, array $staff): array
    {
        $db = $this->db();
        $row = $db->table('engage_token_rates')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return self::fail(404, 'not_found', 'That rate does not exist.');
        }
        if ($row['status'] === 'active') {
            return ['status' => 200, 'data' => $this->find($id)];
        }
        if ($row['status'] !== 'draft') {
            return self::fail(409, 'not_draft', 'Only a draft can be activated.');
        }

        $roles = (array) ($staff['roles'] ?? []);
        $me = (int) ($staff['id'] ?? 0);
        if (! in_array('super_admin', $roles, true)) {
            if ((int) $row['created_by'] === $me) {
                return self::fail(409, 'four_eyes', 'You created this rate — another sales manager or an administrator must approve it.');
            }
            if (in_array($me, self::editors($row), true)) {
                return self::fail(409, 'four_eyes', 'You edited this rate — another sales manager or an administrator must approve it.');
            }
        }

        // One live rate per target. The partial unique index enforces this too; checking here
        // turns a 500 into a sentence naming the rate already in the way.
        $clash = $db->query(
            "SELECT rate_ref FROM engage_token_rates
              WHERE status = 'active' AND provider_code = ? AND COALESCE(model_key, '*') = ?",
            [$row['provider_code'], $row['model_key'] ?? '*']
        )->getRowArray();
        if ($clash !== null) {
            return self::fail(409, 'rate_exists', "{$clash['rate_ref']} is already the active rate for that model. Retire it first.", ['rate_ref' => $clash['rate_ref']]);
        }

        $db->table('engage_token_rates')->where('id', $id)->update([
            'status'         => 'active',
            'approved_by'    => $me ?: null,
            'approved_at'    => date('Y-m-d H:i:sP'),
            'effective_from' => $row['effective_from'] ?? date('Y-m-d H:i:sP'),
            'version'        => (int) $row['version'] + 1,
            'updated_at'     => date('Y-m-d H:i:sP'),
        ]);
        $this->audit('token_rate.activate', $id, [
            'rate_ref'   => $row['rate_ref'],
            'provider'   => $row['provider_code'],
            'model'      => $row['model_key'],
            'multiplier' => TokenRateCard::describe((int) $row['multiplier_bp']),
        ]);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    public function retire(int $id, array $in, array $staff): array
    {
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            return self::fail(422, 'validation_failed', 'Say why this rate is being retired.', ['reason' => 'At least 3 characters.']);
        }
        $row = $this->db()->table('engage_token_rates')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return self::fail(404, 'not_found', 'That rate does not exist.');
        }
        if ($row['status'] === 'retired') {
            return ['status' => 200, 'data' => $this->find($id)];
        }
        // effective_to is closed in SQL, not from PHP's clock, because engage_token_rates_window_chk
        // requires it to be strictly after effective_from — and a rate activated and retired within
        // the same second (a mistake caught immediately, which is exactly when someone retires one)
        // would otherwise be refused by the database with a 500.
        $this->db()->query(
            "UPDATE engage_token_rates
                SET status       = 'retired',
                    retired_by   = ?,
                    retired_at   = NOW(),
                    effective_to = GREATEST(NOW(), COALESCE(effective_from, NOW()) + INTERVAL '1 microsecond'),
                    version      = version + 1,
                    updated_at   = NOW()
              WHERE id = ?",
            [(int) ($staff['id'] ?? 0) ?: null, $id]
        );
        $this->audit('token_rate.retire', $id, ['rate_ref' => $row['rate_ref'], 'reason' => $reason]);

        return ['status' => 200, 'data' => $this->find($id)];
    }

    /**
     * Change what tokens cost and how the tier fallbacks weigh them.
     *
     * Every field is optional: a screen that only changes the price must not silently reset the
     * tier table. Each value is validated on its own and an invalid one is refused rather than
     * clamped, because a price nobody meant is worse than an error message.
     */
    public function savePricing(array $in, array $staff): array
    {
        $s = new SettingsModel();
        $userId = (int) ($staff['id'] ?? 0) ?: null;
        $errors = [];
        $changed = [];

        if (array_key_exists('paise_per_1000_tokens', $in)) {
            $p = (int) $in['paise_per_1000_tokens'];
            if ($p < 1 || $p > 1000000) {
                $errors['paise_per_1000_tokens'] = 'Paise per 1,000 tokens, between 1 and 1,000,000. 100 = Rs 1.00.';
            } else {
                $s->setSetting(self::SETTING_PRICE, $p, $userId);
                $changed['paise_per_1000_tokens'] = $p;
            }
        }
        if (array_key_exists('gst_rate', $in)) {
            $g = (float) $in['gst_rate'];
            if ($g < 0 || $g >= 100) {
                $errors['gst_rate'] = 'A percentage below 100.';
            } else {
                $s->setSetting(self::SETTING_GST, $g, $userId);
                $changed['gst_rate'] = $g;
            }
        }
        if (array_key_exists('sac', $in)) {
            $sac = trim((string) $in['sac']);
            // Finance refuses anything else when it issues the invoice, so refuse it here rather
            // than at the end of a checkout the customer has already paid for.
            if (preg_match('/\A99\d{4}\z/', $sac) !== 1) {
                $errors['sac'] = 'Finance accepts 99 followed by four digits, e.g. 997331.';
            } else {
                $s->setSetting(self::SETTING_SAC, $sac, $userId);
                $changed['sac'] = $sac;
            }
        }
        if (array_key_exists('tier_fallback_bp', $in) && is_array($in['tier_fallback_bp'])) {
            $tiers = [];
            foreach (['low', 'medium', 'high'] as $tier) {
                if (! array_key_exists($tier, $in['tier_fallback_bp'])) {
                    continue;
                }
                $bp = (int) $in['tier_fallback_bp'][$tier];
                if ($bp < 1 || $bp > 10000000) {
                    $errors["tier_fallback_bp.{$tier}"] = 'Basis points of 1x, between 1 and 10,000,000 (10000 = 1x).';
                } else {
                    $tiers[$tier] = $bp;
                }
            }
            if ($tiers !== []) {
                $merged = $tiers + $this->tierFallback();
                $s->setSetting(self::SETTING_TIERS, $merged, $userId);
                $changed['tier_fallback_bp'] = $merged;
            }
        }
        if (array_key_exists('unclassified_tier', $in)) {
            $t = strtolower(trim((string) $in['unclassified_tier']));
            if (! in_array($t, ['low', 'medium', 'high'], true)) {
                $errors['unclassified_tier'] = 'low, medium or high.';
            } else {
                $s->setSetting(self::SETTING_UNCLASSIFIED, $t, $userId);
                $changed['unclassified_tier'] = $t;
            }
        }

        if ($errors !== []) {
            return self::fail(422, 'validation_failed', 'Please correct the pricing.', $errors);
        }
        if ($changed === []) {
            return self::fail(422, 'nothing_to_change', 'Send at least one setting to change.');
        }
        $this->audit('token_rate.pricing', 0, $changed);

        return ['status' => 200, 'data' => [
            'pricing'           => $this->pricing(),
            'tier_fallback'     => $this->tierFallback(),
            'unclassified_tier' => $this->unclassifiedTier(),
        ]];
    }

    // --------------------------------------------------------------- helpers

    /**
     * Every Console model priced against the card, so Engage can show what each one actually
     * costs a customer and name the ones nobody has set a rate for.
     *
     * @param list<array<string,mixed>> $models Console's catalogue
     * @return array{models:list<array<string,mixed>>, warnings:list<string>}
     */
    public function priceCatalogue(array $models): array
    {
        $card = $this->card();
        $out = [];
        $warnings = [];
        foreach ($models as $m) {
            $provider = (string) ($m['provider']['slug'] ?? $m['provider_slug'] ?? '');
            $modelKey = (string) ($m['model_key'] ?? '');
            if ($provider === '' || $modelKey === '') {
                continue;
            }
            $r = $card->resolve($provider, $modelKey, $m['tier'] ?? null);
            // "In use" means a Console module is bound to it. A model nobody runs still gets a
            // row — the founder asked for the unused ones to be visible as inactive, not hidden.
            $inUse = (int) ($m['module_count'] ?? 0) > 0 && (string) ($m['status'] ?? '') === 'active';
            if ($inUse && $r['warning'] !== null) {
                $warnings[] = $r['warning'];
            }
            $out[] = [
                'provider_slug' => $provider,
                'provider_name' => (string) ($m['provider']['name'] ?? $provider),
                'model_key'     => $modelKey,
                'display_name'  => (string) ($m['display_name'] ?? $modelKey),
                'status'        => (string) ($m['status'] ?? 'active'),
                'tier'          => $m['tier'] ?? null,
                'in_use'        => $inUse,
                'module_count'  => (int) ($m['module_count'] ?? 0),
                'multiplier_bp' => $r['multiplier_bp'],
                'multiplier'    => TokenRateCard::describe($r['multiplier_bp']),
                'source'        => $r['source'],
                'warning'       => $r['warning'],
            ];
        }

        return ['models' => $out, 'warnings' => $warnings];
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private static function validate(array $in): array
    {
        $errors = [];
        $provider = strtolower(trim((string) ($in['provider_code'] ?? '')));
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,47}\z/', $provider) !== 1) {
            $errors['provider_code'] = 'The provider slug as Console holds it, e.g. anthropic.';
        }
        $model = trim((string) ($in['model_key'] ?? ''));
        if ($model !== '' && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,95}\z/', $model) !== 1) {
            $errors['model_key'] = 'The model id as Console holds it, or blank for every model of this provider.';
        }

        // Accept either a human multiplier ("25" or "1.5") or raw basis points, because a rate
        // card screen types the first and an import sends the second.
        $bp = null;
        if (isset($in['multiplier_bp']) && $in['multiplier_bp'] !== '') {
            $bp = (int) $in['multiplier_bp'];
        } elseif (isset($in['multiplier']) && $in['multiplier'] !== '') {
            $m = (float) $in['multiplier'];
            $bp = (int) round($m * TokenRateCard::ONE_X);
        }
        if ($bp === null) {
            $errors['multiplier'] = 'How many times a token counts, e.g. 1, 10 or 25.';
        } elseif ($bp < 0 || $bp > 10000000) {
            $errors['multiplier'] = 'Between 0 and 1000.';
        }

        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'A short name for this rate, up to 120 characters.';
        }

        $from = trim((string) ($in['effective_from'] ?? ''));
        $to = trim((string) ($in['effective_to'] ?? ''));
        if ($from !== '' && strtotime($from) === false) {
            $errors['effective_from'] = 'A date, or blank for "as soon as it is activated".';
        }
        if ($to !== '' && strtotime($to) === false) {
            $errors['effective_to'] = 'A date, or blank for "until it is retired".';
        }
        if ($errors !== []) {
            return [[], $errors];
        }

        return [[
            'provider_code'  => $provider,
            'model_key'      => $model === '' ? null : $model,
            'multiplier_bp'  => $bp,
            'label'          => $label,
            'notes'          => trim((string) ($in['notes'] ?? '')) ?: null,
            'effective_from' => $from === '' ? null : date('Y-m-d H:i:sP', (int) strtotime($from)),
            'effective_to'   => $to === '' ? null : date('Y-m-d H:i:sP', (int) strtotime($to)),
        ], []];
    }

    /** @return list<int> */
    private static function editors(array $row): array
    {
        $raw = (string) ($row['edited_by'] ?? '{}');
        $inner = trim($raw, '{}');

        return $inner === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $inner))));
    }

    private static function view(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'rate_ref'       => (string) $r['rate_ref'],
            'provider_code'  => (string) $r['provider_code'],
            'model_key'      => $r['model_key'],
            'multiplier_bp'  => (int) $r['multiplier_bp'],
            'multiplier'     => TokenRateCard::describe((int) $r['multiplier_bp']),
            'label'          => (string) $r['label'],
            'notes'          => $r['notes'],
            'effective_from' => $r['effective_from'],
            'effective_to'   => $r['effective_to'],
            'status'         => (string) $r['status'],
            'approved_at'    => $r['approved_at'],
            'retired_at'     => $r['retired_at'],
            'version'        => (int) $r['version'],
            'created_at'     => $r['created_at'],
        ];
    }

    private static function fail(int $status, string $code, string $msg, array $details = []): array
    {
        return ['status' => $status, 'code' => $code, 'error' => $msg] + ($details ? ['details' => $details] : []);
    }

    private function audit(string $event, int $id, array $meta): void
    {
        Services::auditService()->log($event, ['subject_kind' => 'token_rate', 'subject_id' => $id, 'metadata' => $meta, 'fanout_console' => true]);
    }
}

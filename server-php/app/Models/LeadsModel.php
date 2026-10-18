<?php

namespace App\Models;

class LeadsModel extends EngageModel
{
    protected $table         = 'engage_leads';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'lead_code', 'name', 'organization', 'account_id', 'contact_id',
        'email', 'mobile', 'whatsapp',
        'source_portal', 'source_campaign', 'source_type', 'lead_source_id',
        'interested_product', 'interested_product_id', 'subscription_interest',
        'expected_users', 'expected_companies',
        'lead_score', 'stage', 'priority', 'owner_id', 'sales_status',
        'next_follow_up_at', 'last_contacted_at', 'conversion_probability',
        'notes', 'bot_summary', 'metadata',
        // Seller programme / Reach attribution (migration 2026-10-17-000091). Never accepted from a
        // staff PUT body (LeadsController::PROTECTED_FIELDS) — only the attribution services set them.
        'seller_id', 'seller_attribution', 'seller_attributed_at', 'seller_protection_expires_at',
        'reach_lead_id', 'mobile_normalized', 'attribution', 'converted_at',
    ];

    protected array $casts = ['metadata' => '?json-array', 'attribution' => '?json-array'];

    protected $beforeInsert = ['fillMobileNormalized'];
    protected $beforeUpdate = ['fillMobileNormalized'];

    /** Last 10 digits of a phone number (Indian mobile without +91 / 0), or null when too short. */
    public static function normaliseMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    protected function fillMobileNormalized(array $data): array
    {
        if (! isset($data['data']) || ! is_array($data['data'])) {
            return $data;
        }
        if (array_key_exists('mobile', $data['data']) || array_key_exists('whatsapp', $data['data'])) {
            $m = self::normaliseMobile($data['data']['mobile'] ?? null) ?? self::normaliseMobile($data['data']['whatsapp'] ?? null);
            if ($m !== null || array_key_exists('mobile', $data['data'])) {
                $data['data']['mobile_normalized'] = $m;
            }
        }

        return $data;
    }

    /**
     * Existing lead for a contact: case-insensitive email first, then the normalised mobile.
     * Seller-programme requests (source_type seller_request) are a different relationship and never match.
     *
     * @return array{0: ?array, 1: ?string} [lead, matched_on 'email'|'mobile'|null]
     */
    public function findDuplicate(?string $email, ?string $mobile): array
    {
        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $row = $this->db->query('SELECT id FROM engage_leads WHERE LOWER(email) = ? AND source_type <> ? ORDER BY id ASC LIMIT 1', [$email, 'seller_request'])->getRowArray();
            if ($row) {
                return [$this->find((int) $row['id']), 'email'];
            }
        }
        $m = self::normaliseMobile($mobile);
        if ($m !== null) {
            $row = $this->db->query('SELECT id FROM engage_leads WHERE mobile_normalized = ? AND source_type <> ? ORDER BY id ASC LIMIT 1', [$m, 'seller_request'])->getRowArray();
            if ($row) {
                return [$this->find((int) $row['id']), 'mobile'];
            }
        }

        return [null, null];
    }

    public function generateLeadCode(): string
    {
        $prefix = 'ENG-' . date('Ymd') . '-';
        $count  = $this->like('lead_code', $prefix, 'after')->countAllResults();
        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    public function findByEmail(string $email): ?array
    {
        if ($email === '') return null;
        return $this->where('LOWER(email)', strtolower(trim($email)))->orderBy('id', 'DESC')->first();
    }
}

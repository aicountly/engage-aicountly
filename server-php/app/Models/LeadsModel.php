<?php

namespace App\Models;

use CodeIgniter\Model;

class LeadsModel extends Model
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
    ];

    protected array $casts = ['metadata' => 'json-array'];

    public function generateLeadCode(): string
    {
        $prefix = 'ENG-' . date('Ymd') . '-';
        $count  = $this->like('lead_code', $prefix, 'after')->countAllResults();
        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    public function findByEmail(string $email): ?array
    {
        if ($email === '') return null;
        return $this->where('email', $email)->orderBy('id', 'DESC')->first();
    }
}

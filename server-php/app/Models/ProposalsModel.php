<?php

namespace App\Models;

use CodeIgniter\Model;

class ProposalsModel extends Model
{
    protected $table         = 'engage_proposals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'proposal_code', 'lead_id', 'account_id', 'contact_id',
        'title', 'summary', 'currency',
        'total_amount', 'discount_amount', 'net_amount',
        'status', 'valid_until', 'sent_at', 'accepted_at', 'declined_at',
        'owner_id', 'notes', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];

    public function generateCode(): string
    {
        $prefix = 'PROP-' . date('Ymd') . '-';
        $count  = $this->like('proposal_code', $prefix, 'after')->countAllResults();
        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}

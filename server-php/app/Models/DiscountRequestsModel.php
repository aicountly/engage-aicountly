<?php

namespace App\Models;

use CodeIgniter\Model;

class DiscountRequestsModel extends Model
{
    protected $table         = 'engage_discount_requests';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'lead_id', 'proposal_id', 'plan_id', 'requested_by', 'requester_kind',
        'discount_percent', 'discount_amount', 'currency', 'justification',
        'status', 'approval_id', 'decided_by', 'decided_at', 'decision_notes', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];
}

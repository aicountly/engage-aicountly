<?php

namespace App\Models;

use CodeIgniter\Model;

class RenewalsModel extends Model
{
    protected $table         = 'engage_renewals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'account_id', 'lead_id', 'product_id', 'plan_id',
        'external_ref', 'current_end_date', 'next_renewal_date',
        'target_amount', 'currency', 'status', 'reminder_stage',
        'owner_id', 'notes', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];

    public function dueWithin(int $days): array
    {
        $today = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+{$days} days"));
        return $this->where('status', 'upcoming')
            ->where('next_renewal_date >=', $today)
            ->where('next_renewal_date <=', $end)
            ->orderBy('next_renewal_date', 'ASC')
            ->findAll(500);
    }
}

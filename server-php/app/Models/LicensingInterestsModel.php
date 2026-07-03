<?php

namespace App\Models;

use CodeIgniter\Model;

class LicensingInterestsModel extends Model
{
    protected $table         = 'engage_licensing_interests';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'lead_id', 'account_id', 'product_id', 'plan_id',
        'expected_users', 'expected_companies', 'requested_start', 'billing_cycle',
        'status', 'notes', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class SubscriptionInquiriesModel extends Model
{
    protected $table         = 'engage_subscription_inquiries';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'lead_id', 'account_id', 'product_id', 'plan_id',
        'billing_cycle', 'qty_users', 'qty_companies',
        'target_amount', 'currency', 'status', 'convert_placeholder',
        'notes', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];
}

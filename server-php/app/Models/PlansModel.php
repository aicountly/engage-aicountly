<?php

namespace App\Models;

use CodeIgniter\Model;

class PlansModel extends Model
{
    protected $table         = 'engage_plans';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'product_id', 'name', 'billing_cycle', 'base_price', 'currency',
        'user_included', 'company_included', 'features', 'is_active', 'description',
    ];

    protected array $casts = ['features' => 'json-array'];
}

<?php

namespace App\Models;

class PlansModel extends EngageModel
{
    protected $table         = 'engage_plans';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'product_id', 'name', 'billing_cycle', 'base_price', 'currency',
        'user_included', 'company_included', 'features', 'is_active', 'description',
    ];

    protected array $casts = ['features' => '?json-array'];
}

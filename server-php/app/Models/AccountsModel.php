<?php

namespace App\Models;

use CodeIgniter\Model;

class AccountsModel extends Model
{
    protected $table         = 'engage_accounts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'legal_name', 'website', 'industry', 'size_bucket', 'country', 'city',
        'status', 'owner_id', 'notes', 'metadata',
    ];

    protected array $casts = ['metadata' => 'json-array'];
}

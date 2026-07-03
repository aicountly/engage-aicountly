<?php

namespace App\Models;

use CodeIgniter\Model;

class LeadSourcesModel extends Model
{
    protected $table         = 'engage_lead_sources';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'name', 'source_type', 'source_portal',
        'default_weight', 'is_active', 'description',
    ];
}

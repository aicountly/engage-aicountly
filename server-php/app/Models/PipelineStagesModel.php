<?php

namespace App\Models;

use CodeIgniter\Model;

class PipelineStagesModel extends Model
{
    protected $table         = 'engage_pipeline_stages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'name', 'sort_order', 'is_terminal', 'is_won', 'is_lost',
        'default_probability', 'colour', 'description',
    ];

    public function ordered(): array
    {
        return $this->orderBy('sort_order', 'ASC')->findAll();
    }
}

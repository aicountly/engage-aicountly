<?php

namespace App\Models;

use CodeIgniter\Model;

class ProposalLinesModel extends Model
{
    protected $table         = 'engage_proposal_lines';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'proposal_id', 'product_id', 'plan_id', 'sort_order',
        'description', 'quantity', 'unit_price', 'discount_percent', 'line_total', 'notes',
    ];
}

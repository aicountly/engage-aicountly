<?php

namespace App\Models;

use CodeIgniter\Model;

class BotActionsModel extends Model
{
    protected $table         = 'engage_bot_actions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'name', 'category', 'risk_level', 'default_approval',
        'is_auto_eligible', 'description',
    ];
}

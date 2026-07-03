<?php

namespace App\Models;

use CodeIgniter\Model;

class BotReportsModel extends Model
{
    protected $table         = 'engage_bot_reports';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'queue_id', 'action', 'entity_kind', 'entity_id', 'mode',
        'understanding', 'data_accessed', 'recommendation', 'action_proposed', 'action_taken',
        'approval_status', 'approval_id',
        'message_draft', 'proposal_draft', 'evidence',
        'next_recommended_action', 'errors', 'created_at',
    ];
    protected array $casts = [
        'data_accessed'  => 'json-array',
        'proposal_draft' => 'json-array',
        'evidence'       => 'json-array',
        'errors'         => 'json-array',
    ];
}

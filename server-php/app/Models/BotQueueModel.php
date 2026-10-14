<?php

namespace App\Models;

class BotQueueModel extends EngageModel
{
    protected $table         = 'engage_bot_queue';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'action', 'entity_kind', 'entity_id', 'payload', 'mode', 'status',
        'attempts', 'last_error', 'run_at', 'started_at', 'finished_at',
        'requested_by', 'requester_kind', 'report_id', 'approval_id',
    ];
    protected array $casts = ['payload' => '?json-array'];
}

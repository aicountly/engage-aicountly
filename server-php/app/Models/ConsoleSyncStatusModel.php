<?php

namespace App\Models;

class ConsoleSyncStatusModel extends EngageModel
{
    protected $table         = 'engage_console_sync_status';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'event_kind', 'correlation_id', 'direction', 'http_status', 'success',
        'error_message', 'request_payload', 'response_payload', 'created_at',
    ];
    protected array $casts = ['request_payload' => '?json-array', 'response_payload' => '?json-array'];

    public function record(array $row): int
    {
        $row['created_at'] = date('Y-m-d H:i:s');
        return (int) $this->insert($row, true);
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class ConsoleSyncStatusModel extends Model
{
    protected $table         = 'engage_console_sync_status';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'event_kind', 'correlation_id', 'direction', 'http_status', 'success',
        'error_message', 'request_payload', 'response_payload', 'created_at',
    ];
    protected array $casts = ['request_payload' => 'json-array', 'response_payload' => 'json-array'];

    public function record(array $row): int
    {
        if (isset($row['request_payload']) && is_array($row['request_payload'])) {
            $row['request_payload']  = json_encode($row['request_payload']);
        }
        if (isset($row['response_payload']) && is_array($row['response_payload'])) {
            $row['response_payload'] = json_encode($row['response_payload']);
        }
        $row['created_at'] = date('Y-m-d H:i:s');
        return (int) $this->insert($row, true);
    }
}

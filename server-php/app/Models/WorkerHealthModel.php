<?php

namespace App\Models;

use CodeIgniter\Model;

class WorkerHealthModel extends Model
{
    protected $table         = 'engage_worker_health';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'job_kind', 'status', 'http_status', 'latency_ms',
        'error_message', 'metadata', 'created_at',
    ];
    protected array $casts = ['metadata' => 'json-array'];

    public function record(array $row): int
    {
        if (isset($row['metadata']) && is_array($row['metadata'])) {
            $row['metadata'] = json_encode($row['metadata']);
        }
        $row['created_at'] = date('Y-m-d H:i:s');
        return (int) $this->insert($row, true);
    }
}

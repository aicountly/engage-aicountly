<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogsModel extends Model
{
    protected $table         = 'engage_audit_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'event', 'actor_id', 'actor_email', 'actor_role',
        'subject_kind', 'subject_id',
        'ip_address', 'user_agent', 'metadata', 'created_at',
    ];

    protected array $casts = ['metadata' => 'json-array'];
}

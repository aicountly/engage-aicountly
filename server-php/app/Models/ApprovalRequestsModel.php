<?php

namespace App\Models;

use App\Models\Cast\TolerantJsonCast;
use CodeIgniter\Model;

class ApprovalRequestsModel extends Model
{
    protected $table         = 'engage_approval_requests';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'correlation_id', 'kind', 'subject_kind', 'subject_id', 'risk_level', 'status', 'payload',
        'requested_by', 'requester_kind', 'decided_by', 'decided_at', 'decision_notes',
        'console_approval_id', 'console_status', 'production_confirmation',
        'executed_at', 'execution_result',
    ];
    protected array $casts = ['payload' => '?json-array', 'execution_result' => '?json-array'];

    /** Rows written before 2026-10-14 hold payload / execution_result double-encoded. */
    protected array $castHandlers = ['json' => TolerantJsonCast::class];
}

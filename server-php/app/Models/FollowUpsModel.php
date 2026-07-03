<?php

namespace App\Models;

use CodeIgniter\Model;

class FollowUpsModel extends Model
{
    protected $table         = 'engage_follow_ups';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'lead_id', 'proposal_id', 'renewal_id',
        'due_at', 'kind', 'channel', 'title', 'body',
        'owner_id', 'status', 'completed_at', 'outcome', 'created_by_kind', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];

    public function upcoming(int $days = 7): array
    {
        $from = date('Y-m-d H:i:s');
        $to   = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        return $this->where('status', 'pending')
            ->where('due_at >=', $from)
            ->where('due_at <=', $to)
            ->orderBy('due_at', 'ASC')
            ->findAll(100);
    }

    public function overdue(): array
    {
        return $this->where('status', 'pending')
            ->where('due_at <', date('Y-m-d H:i:s'))
            ->orderBy('due_at', 'ASC')
            ->findAll(200);
    }
}

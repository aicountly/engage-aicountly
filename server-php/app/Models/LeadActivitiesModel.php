<?php

namespace App\Models;

class LeadActivitiesModel extends EngageModel
{
    protected $table         = 'engage_lead_activities';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'lead_id', 'activity_type', 'title', 'body',
        'author_id', 'author_kind', 'metadata', 'created_at',
    ];

    protected array $casts = ['metadata' => '?json-array'];

    public function record(int $leadId, string $type, ?string $title, ?string $body, array $opts = []): int
    {
        $req = service('request');
        $data = [
            'lead_id'       => $leadId,
            'activity_type' => $type,
            'title'         => $title,
            'body'          => $body,
            'author_id'     => $opts['author_id']   ?? ($req->engageUser['id'] ?? null),
            'author_kind'   => $opts['author_kind'] ?? 'user',
            'metadata'      => $opts['metadata'] ?? null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        return (int) $this->insert($data, true);
    }
}

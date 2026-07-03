<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunicationDraftsModel extends Model
{
    protected $table         = 'engage_communication_drafts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'lead_id', 'proposal_id', 'channel', 'to_address', 'subject', 'body',
        'attachments', 'status', 'approval_id',
        'created_by', 'creator_kind', 'approved_by', 'approved_at', 'sent_at', 'metadata',
    ];
    protected array $casts = ['attachments' => 'json-array', 'metadata' => 'json-array'];
}

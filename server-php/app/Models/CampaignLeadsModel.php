<?php

namespace App\Models;

use CodeIgniter\Model;

class CampaignLeadsModel extends Model
{
    protected $table         = 'engage_campaign_leads';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['campaign_id', 'lead_id', 'attribution', 'metadata', 'created_at'];
    protected array $casts = ['metadata' => 'json-array'];

    public function link(int $campaignId, int $leadId, string $attribution = 'primary', ?array $metadata = null): void
    {
        $exists = $this->where('campaign_id', $campaignId)->where('lead_id', $leadId)->countAllResults() > 0;
        if ($exists) return;
        $this->insert([
            'campaign_id' => $campaignId,
            'lead_id'     => $leadId,
            'attribution' => $attribution,
            'metadata'    => $metadata ? json_encode($metadata) : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

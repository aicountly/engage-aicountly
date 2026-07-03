<?php

namespace App\Models;

use CodeIgniter\Model;

class CampaignsModel extends Model
{
    protected $table         = 'engage_campaigns';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'external_code', 'name', 'source_portal', 'campaign_kind',
        'status', 'started_at', 'ended_at', 'metadata',
    ];
    protected array $casts = ['metadata' => 'json-array'];

    public function findOrCreateBySource(string $portal, ?string $externalCode, string $name, array $extra = []): array
    {
        if ($externalCode !== null && $externalCode !== '') {
            $row = $this->where('source_portal', $portal)->where('external_code', $externalCode)->first();
            if ($row) {
                return $row;
            }
        }

        $row = array_merge([
            'source_portal' => $portal,
            'external_code' => $externalCode,
            'name'          => $name ?: ($externalCode ?: 'Untitled Campaign'),
            'status'        => 'active',
        ], $extra);

        if (isset($row['metadata']) && is_array($row['metadata'])) {
            $row['metadata'] = json_encode($row['metadata']);
        }

        $id = $this->insert($row, true);
        return $this->find($id) ?? $row;
    }
}

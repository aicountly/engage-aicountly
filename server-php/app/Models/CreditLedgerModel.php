<?php

namespace App\Models;

use CodeIgniter\Model;

class CreditLedgerModel extends Model
{
    protected $table         = 'engage_credit_ledger';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'party_type', 'party_reference', 'credit_type', 'direction',
        'amount', 'points_amount', 'currency', 'points_unit',
        'source', 'linked_kind', 'linked_id', 'status', 'approval_id',
        'remarks', 'created_by', 'creator_kind', 'approved_by', 'approved_at',
        'metadata', 'created_at',
    ];
    protected array $casts = ['metadata' => 'json-array'];

    public function partyBalance(string $partyType, string $partyRef, ?string $creditType = null): array
    {
        $qb = $this->db->table('engage_credit_ledger')
            ->select('direction, SUM(amount) as amount_total, SUM(points_amount) as points_total')
            ->where('party_type', $partyType)
            ->where('party_reference', $partyRef)
            ->where('status', 'posted')
            ->groupBy('direction');

        if ($creditType) {
            $qb->where('credit_type', $creditType);
        }

        $rows = $qb->get()->getResultArray();
        $sum = ['amount' => 0.0, 'points' => 0.0];
        foreach ($rows as $r) {
            $sign = $r['direction'] === 'credit' ? 1 : -1;
            $sum['amount'] += $sign * (float) $r['amount_total'];
            $sum['points'] += $sign * (float) $r['points_total'];
        }
        return $sum;
    }
}

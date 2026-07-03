<?php

namespace App\Services;

use App\Models\LeadSourcesModel;
use App\Models\SettingsModel;

/**
 * Deterministic lead-scoring engine. Produces:
 *   - score       (0-100)
 *   - conversion  (0-100)
 *   - factors     (array of "why" strings)
 *   - bucket      hot | warm | cold
 */
class LeadScoringService
{
    public function score(array $lead): array
    {
        $score = 0;
        $factors = [];

        $sourceWeight = $this->sourceWeight($lead);
        if ($sourceWeight > 0) {
            $score += $sourceWeight;
            $factors[] = "Source weight +{$sourceWeight}";
        }

        $product = (string) ($lead['interested_product'] ?? '');
        if ($product !== '' && $product !== 'other') {
            $score += 10;
            $factors[] = 'Specific product interest (+10)';
        }

        $expectedUsers = (int) ($lead['expected_users'] ?? 0);
        if ($expectedUsers >= 100) {
            $score += 20;
            $factors[] = 'Enterprise user count 100+ (+20)';
        } elseif ($expectedUsers >= 25) {
            $score += 12;
            $factors[] = 'Mid-market 25+ users (+12)';
        } elseif ($expectedUsers >= 5) {
            $score += 5;
            $factors[] = 'SMB 5+ users (+5)';
        }

        if (! empty($lead['email'])) {
            $score += 6;
            $factors[] = 'Business email present (+6)';
        }
        if (! empty($lead['mobile']) || ! empty($lead['whatsapp'])) {
            $score += 6;
            $factors[] = 'Phone/WhatsApp present (+6)';
        }
        if (! empty($lead['subscription_interest'])) {
            $score += 8;
            $factors[] = 'Explicit subscription interest (+8)';
        }

        $stagePriority = [
            'new' => 0, 'qualified' => 5, 'contacted' => 8,
            'demo_required' => 10, 'proposal_required' => 12, 'proposal_sent' => 15,
            'negotiation' => 18, 'waiting_for_approval' => 18,
            'converted' => 25, 'nurture' => 4,
        ];
        $stage = (string) ($lead['stage'] ?? 'new');
        if (isset($stagePriority[$stage])) {
            $score += $stagePriority[$stage];
            if ($stagePriority[$stage] > 0) {
                $factors[] = "Stage {$stage} (+{$stagePriority[$stage]})";
            }
        }

        // Freshness bonus for recently touched leads.
        if (! empty($lead['last_contacted_at'])) {
            $days = (int) ((time() - strtotime((string) $lead['last_contacted_at'])) / 86400);
            if ($days <= 3) {
                $score += 6;
                $factors[] = 'Recently contacted (+6)';
            } elseif ($days <= 14) {
                $score += 3;
                $factors[] = 'Contacted within 14 days (+3)';
            } else {
                $factors[] = "Not contacted for {$days} days (stale)";
                $score -= min(10, (int) ($days / 7));
            }
        }

        $score = max(0, min(100, $score));

        $thresholds = (array) (new SettingsModel())->getSetting('lead_score_thresholds', ['hot' => 75, 'warm' => 50, 'cold' => 25]);
        $bucket = $score >= (int) ($thresholds['hot'] ?? 75) ? 'hot'
            : ($score >= (int) ($thresholds['warm'] ?? 50) ? 'warm' : 'cold');

        // Conversion probability: rough calibration keyed to stage.
        $stageProbability = [
            'new' => 5, 'qualified' => 15, 'contacted' => 25,
            'demo_required' => 35, 'proposal_required' => 45, 'proposal_sent' => 55,
            'negotiation' => 70, 'waiting_for_approval' => 80,
            'converted' => 100, 'nurture' => 15,
            'lost' => 0, 'not_relevant' => 0,
        ];
        $baseProb = $stageProbability[$stage] ?? 10;
        $conv = max(0, min(100, (int) round($baseProb * 0.6 + $score * 0.4)));

        return [
            'score'      => $score,
            'conversion' => $conv,
            'bucket'     => $bucket,
            'factors'    => $factors,
        ];
    }

    private function sourceWeight(array $lead): int
    {
        if (! empty($lead['lead_source_id'])) {
            $row = (new LeadSourcesModel())->find((int) $lead['lead_source_id']);
            if ($row) {
                return (int) $row['default_weight'];
            }
        }
        $sourceType = (string) ($lead['source_type'] ?? '');
        if ($sourceType === '') return 0;
        $row = (new LeadSourcesModel())->where('source_type', $sourceType)->first();
        return $row ? (int) $row['default_weight'] : 10;
    }
}

<?php

namespace App\Services;

use App\Models\SettingsModel;
use GuzzleHttp\Client;

/**
 * LLM client stub for Sales Bot drafts.
 *
 * When ENGAGE_LLM_PROVIDER + ENGAGE_LLM_API_KEY are configured and
 * `llm_enabled` setting is true, this class will call the provider; otherwise
 * it returns deterministic template output so the bot still works locally.
 */
class LlmClient
{
    private string $provider;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->provider = (string) env('ENGAGE_LLM_PROVIDER', '');
        $this->apiKey   = (string) env('ENGAGE_LLM_API_KEY', '');
        $this->model    = (string) env('ENGAGE_LLM_MODEL', '');
    }

    public function isEnabled(): bool
    {
        $enabled = (bool) (new SettingsModel())->getSetting('llm_enabled', false);
        return $enabled && $this->apiKey !== '' && $this->provider !== '';
    }

    /**
     * Draft a short prospecting message. Always safe — no PII leaves Engage
     * except what the caller passes in `context`.
     *
     * Returns ['draft' => string, 'source' => 'llm'|'template', 'meta' => array]
     */
    public function draftMessage(string $kind, array $context = []): array
    {
        if (! $this->isEnabled()) {
            return [
                'draft'  => $this->template($kind, $context),
                'source' => 'template',
                'meta'   => [],
            ];
        }

        // Stub for future OpenAI-compatible integration. The plan explicitly
        // said keep this as a service placeholder; we log the call and fall
        // back to the deterministic template so the bot never breaks.
        log_message('info', 'LLM draftMessage stub called: ' . $kind);
        return [
            'draft'  => $this->template($kind, $context),
            'source' => 'template',
            'meta'   => ['provider' => $this->provider, 'model' => $this->model, 'stub' => true],
        ];
    }

    private function template(string $kind, array $ctx): string
    {
        $name    = (string) ($ctx['lead_name']     ?? 'there');
        $product = (string) ($ctx['product_name']  ?? 'AICOUNTLY');
        $company = (string) ($ctx['organization']  ?? '');
        $owner   = (string) ($ctx['owner_name']    ?? 'AICOUNTLY Sales');
        $stage   = (string) ($ctx['stage']         ?? '');

        switch ($kind) {
            case 'email_intro':
                return "Hi {$name},\n\nThanks for your interest in {$product}. I noticed" .
                    ($company !== '' ? " {$company}" : '') .
                    " may benefit from our platform. Could we schedule a 20-minute call this week?\n\nBest regards,\n{$owner}";
            case 'whatsapp_followup':
                return "Hi {$name}, just following up on {$product}. Happy to send a quick demo link — is now a good time? — {$owner}";
            case 'proposal_summary':
                return "Draft proposal summary for {$company} on {$product}: current stage {$stage}. Includes recommended plan tier, discount justification, and rollout schedule.";
            case 'renewal_nudge':
                return "Hi {$name}, quick heads-up — your {$product} plan is approaching renewal. Would you like me to prepare an updated quote?";
            case 'stale_lead_nudge':
                return "Hi {$name}, checking in on {$product}. Are you still evaluating this internally, or should we pause and reconnect next quarter?";
            default:
                return "Hi {$name}, this is {$owner} from AICOUNTLY — reaching out about {$product}.";
        }
    }
}

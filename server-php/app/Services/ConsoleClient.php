<?php

namespace App\Services;

use App\Models\ConsoleSyncStatusModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Outbound client for console.aicountly.org's Bot Orchestrator.
 *
 * All calls include:
 *   X-Console-Portal:      engage
 *   X-Console-Portal-Key:  CONSOLE_INBOUND_KEY   (shared secret, both sides; Console's
 *                                                 bot_portals entry for `engage`)
 *   Content-Type:          application/json
 *
 * The Console routes it calls (console-react-app Config/Routes.php, BotApprovalController):
 *   POST /portal/bot-approval-request   {action_type, risk_level, summary, action_payload,
 *                                        external_ref, correlation_id, source_module}  → data.id
 *   POST /portal/bot-execution-result   {approval_id (Console's), success, result, error}
 * Console then decides in its Bot Orchestrator and calls back
 * POST /api/v1/portal/bot/approval-callback (ApprovalCallbackController).
 *
 * Removed 2026-10-14 — Console has never had these routes (none in its history):
 *   portal/bot-mode-status, portal/bot-health, portal/bot-reports. Console reads Engage's health,
 *   reports and mode from Engage's own portal/bot/* endpoints instead, and a mode change is the
 *   auto_mode.change audit event.
 * Audit no longer goes to portal/bot-audit (which requires event_type and was refusing Engage's
 * {event} body with 422): AuditService sends it to Console's central audit, POST /portal/audit
 * (ConsoleAuditOutbox).
 */
class ConsoleClient
{
    private ?Client $http = null;
    private string $baseUrl;
    private string $key;
    private string $portalCode = 'engage';
    private ConsoleSyncStatusModel $log;

    public function __construct(?Client $http = null)
    {
        $this->http    = $http;
        $this->baseUrl = rtrim((string) env('CONSOLE_API_BASE_URL', ''), '/');
        $this->key     = (string) env('CONSOLE_INBOUND_KEY', '');
        $this->log     = new ConsoleSyncStatusModel();
    }

    private function client(): Client
    {
        if ($this->http instanceof Client) {
            return $this->http;
        }
        $this->http = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => 10,
            'headers'  => [
                'X-Console-Portal'     => $this->portalCode,
                'X-Console-Portal-Key' => $this->key,
                'Content-Type'         => 'application/json',
                'Accept'               => 'application/json',
            ],
        ]);
        return $this->http;
    }

    private function post(string $path, string $eventKind, array $body): ?array
    {
        if ($this->baseUrl === '' || $this->key === '') {
            $this->log->record([
                'event_kind'      => $eventKind,
                'correlation_id'  => $body['correlation_id'] ?? null,
                'direction'       => 'outbound',
                'http_status'     => 0,
                'success'         => false,
                'error_message'   => 'Console integration not configured (missing CONSOLE_API_BASE_URL or CONSOLE_INBOUND_KEY)',
                'request_payload' => $body,
            ]);
            return null;
        }

        try {
            $res = $this->client()->post($path, ['json' => $body]);
            $code = (int) $res->getStatusCode();
            $decoded = json_decode((string) $res->getBody(), true);
            $this->log->record([
                'event_kind'       => $eventKind,
                'correlation_id'   => $body['correlation_id'] ?? null,
                'direction'        => 'outbound',
                'http_status'      => $code,
                'success'          => $code >= 200 && $code < 300,
                'request_payload'  => $body,
                'response_payload' => is_array($decoded) ? $decoded : null,
            ]);
            return is_array($decoded) ? $decoded : null;
        } catch (GuzzleException $e) {
            $this->log->record([
                'event_kind'      => $eventKind,
                'correlation_id'  => $body['correlation_id'] ?? null,
                'direction'       => 'outbound',
                'http_status'     => method_exists($e, 'getCode') ? (int) $e->getCode() : 0,
                'success'         => false,
                'error_message'   => substr($e->getMessage(), 0, 4000),
                'request_payload' => $body,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->log->record([
                'event_kind'      => $eventKind,
                'correlation_id'  => $body['correlation_id'] ?? null,
                'direction'       => 'outbound',
                'http_status'     => 0,
                'success'         => false,
                'error_message'   => substr($e->getMessage(), 0, 4000),
                'request_payload' => $body,
            ]);
            return null;
        }
    }

    /**
     * Queue an approval in Console's Bot Orchestrator; Console's approval id, or null.
     *
     * @param array{correlation_id:string, kind:string, subject_kind?:?string, subject_id?:mixed, risk_level?:string, payload?:mixed, summary?:?string} $req
     */
    public function requestApproval(array $req): ?string
    {
        $res = $this->post('portal/bot-approval-request', 'approval_request', [
            'action_type'    => (string) $req['kind'],
            'risk_level'     => (string) ($req['risk_level'] ?? 'medium'),
            'summary'        => $req['summary'] ?? sprintf('%s for %s #%s', $req['kind'], $req['subject_kind'] ?? 'item', (string) ($req['subject_id'] ?? '')),
            'source_module'  => 'engage',
            'action_payload' => [
                'subject_kind' => $req['subject_kind'] ?? null,
                'subject_id'   => $req['subject_id'] ?? null,
                'payload'      => $req['payload'] ?? null,
            ],
            'external_ref'   => isset($req['subject_id']) ? (string) $req['subject_id'] : null,
            'correlation_id' => (string) $req['correlation_id'],
        ]);
        $id = $res['data']['id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    /** Tell Console how an approved action went (Console's approval id). */
    public function reportExecution(int $consoleApprovalId, bool $success, array $result = [], ?string $error = null): ?array
    {
        return $this->post('portal/bot-execution-result', 'execution_report', [
            'approval_id' => $consoleApprovalId,
            'success'     => $success,
            'result'      => $result,
            'error'       => $error,
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\ConsoleSyncStatusModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Outbound client for console.aicountly.org.
 *
 * All calls include:
 *   X-Console-Portal:      engage
 *   X-Console-Portal-Key:  CONSOLE_INBOUND_KEY   (shared secret, both sides)
 *   Content-Type:          application/json
 *
 * The Console side has matching inbound webhooks:
 *   POST /portal/bot-audit
 *   POST /portal/bot-approval-request
 *   POST /portal/bot-execution-result
 *   POST /portal/bot-mode-status
 *   POST /portal/bot-health
 */
class ConsoleClient
{
    private ?Client $http = null;
    private string $baseUrl;
    private string $key;
    private string $portalCode = 'engage';
    private ConsoleSyncStatusModel $log;

    public function __construct()
    {
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

    public function sendAudit(string $event, array $opts = []): ?array
    {
        return $this->post('portal/bot-audit', 'audit', [
            'portal'        => $this->portalCode,
            'event'         => $event,
            'actor_id'      => $opts['actor_id']     ?? null,
            'actor_email'   => $opts['actor_email']  ?? null,
            'actor_role'    => $opts['actor_role']   ?? null,
            'subject_kind'  => $opts['subject_kind'] ?? null,
            'subject_id'    => $opts['subject_id']   ?? null,
            'metadata'      => $opts['metadata']     ?? null,
            'timestamp'     => gmdate('c'),
        ]);
    }

    public function requestApproval(array $body): ?string
    {
        $body['portal']    = $this->portalCode;
        $body['timestamp'] = gmdate('c');
        $res = $this->post('portal/bot-approval-request', 'approval_request', $body);
        return $res['data']['approval_id'] ?? $res['approval_id'] ?? null;
    }

    public function reportExecution(array $body): ?array
    {
        $body['portal']    = $this->portalCode;
        $body['timestamp'] = gmdate('c');
        return $this->post('portal/bot-execution-result', 'execution_report', $body);
    }

    public function sendModeStatus(string $mode): ?array
    {
        return $this->post('portal/bot-mode-status', 'mode_status', [
            'portal'    => $this->portalCode,
            'mode'      => $mode,
            'timestamp' => gmdate('c'),
        ]);
    }

    public function sendHealth(array $body): ?array
    {
        $body['portal']    = $this->portalCode;
        $body['timestamp'] = gmdate('c');
        return $this->post('portal/bot-health', 'health', $body);
    }

    public function sendReportsSummary(array $body): ?array
    {
        $body['portal']    = $this->portalCode;
        $body['timestamp'] = gmdate('c');
        return $this->post('portal/bot-reports', 'reports_summary', $body);
    }
}

<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use App\Libraries\AicS2s\AicS2sSignature;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Asks Finance for the payment link a token order is paid through.
 *
 * Finance owns Aicountly's merchant accounts, the gateway adapters and the GST
 * invoice; Engage owns what was bought and for whom. So Engage describes the order
 * and Finance produces the link — the same route Academy fees already use
 * (POST /v1/inbound/engage/payment-links, AIC-S2S v1, scope issue), with
 * `purpose: tokens`.
 *
 * THE IDEMPOTENCY KEY IS THE ORDER REFERENCE. A customer who clicks Pay twice, or
 * reloads a slow page, must not end up with two live links for one order — and
 * Finance enforces the same rule on its side with a partial unique index. One order,
 * one link, however many times this is called.
 *
 * Never throws: a link that could not be raised leaves the order `quoted`, which the
 * customer can retry. Nothing has been charged.
 */
final class FinanceLinkClient
{
    public const PATH = '/v1/inbound/engage/payment-links';

    public function __construct(private readonly ?Client $http = null)
    {
    }

    public static function configured(): bool
    {
        return rtrim((string) env('FINANCE_API_BASE_URL', ''), '/') !== ''
            && (string) env('ENGAGE_OUTBOUND_FINANCE_S2S_KEY_ID', '') !== ''
            && strlen((string) env('ENGAGE_OUTBOUND_FINANCE_S2S_SECRET', '')) >= 32;
    }

    /**
     * Aicountly's own GST state as the supplier (ENGAGE_GST_STATE_CODE, two digits), or null when unset. Finance's
     * payment-link check recomputes the split for the payer's state — CGST + SGST within the supplier's state, IGST
     * otherwise — and refuses any other figures (422 fee_breakdown_mismatch), so Engage has to know it.
     */
    public static function supplierStateCode(): ?string
    {
        $s = trim((string) env('ENGAGE_GST_STATE_CODE', ''));

        return preg_match('/\A\d{2}\z/', $s) === 1 ? $s : null;
    }

    /**
     * [cgst, sgst, igst] of a tax amount for a payer in $payerState, exactly as Finance's GstCalculator::split:
     * same state → SGST = floor(tax / 2) and CGST the rest (CGST carries the odd paisa); otherwise IGST. Without
     * ENGAGE_GST_STATE_CODE the answer is [0, 0, 0] — what Engage sent before, which Finance refuses for a taxed line.
     *
     * @return array{0:int, 1:int, 2:int}
     */
    public static function split(int $tax, string $payerState): array
    {
        $supplier = self::supplierStateCode();
        if ($supplier === null) {
            return [0, 0, 0];
        }
        if ($supplier !== $payerState) {
            return [0, 0, $tax];
        }
        $sgst = intdiv($tax, 2);

        return [$tax - $sgst, $sgst, 0];
    }

    /**
     * @param array<string,mixed> $request what TokenOrderService::financeRequest() built
     * @return array{ok:bool, link:?array<string,mixed>, code:?string, message:?string}
     */
    public function raise(string $orderRef, array $request): array
    {
        if (! self::configured()) {
            return ['ok' => false, 'link' => null, 'code' => 'not_configured',
                'message' => 'Finance is not configured here: set FINANCE_API_BASE_URL and the outbound S2S key.'];
        }

        $base = rtrim((string) env('FINANCE_API_BASE_URL', ''), '/');
        $keyId = (string) env('ENGAGE_OUTBOUND_FINANCE_S2S_KEY_ID', '');
        $secret = (string) env('ENGAGE_OUTBOUND_FINANCE_S2S_SECRET', '');
        $environment = (string) env('ENGAGE_S2S_ENVIRONMENT', 'production');

        // Encoded once: the exact bytes are both signed and sent.
        $body = json_encode($request, JSON_UNESCAPED_SLASHES);
        $headers = AicS2sSignature::headers('engage', $keyId, $secret, $environment, 'POST', self::PATH, '', $body, 'service')
            + ['Idempotency-Key' => $orderRef, 'Content-Type' => 'application/json'];

        try {
            $res = ($this->http ?? new Client(['timeout' => 20]))->request('POST', $base . '/api' . self::PATH, [
                'headers' => $headers, 'body' => $body, 'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            $json = json_decode((string) $res->getBody(), true);
            $data = is_array($json) ? ($json['data'] ?? null) : null;

            if ($status >= 200 && $status < 300 && is_array($data) && ($data['url'] ?? '') !== '') {
                return ['ok' => true, 'link' => $data, 'code' => null, 'message' => null];
            }

            $code = is_array($json) ? (string) ($json['error']['code'] ?? $json['code'] ?? "http_{$status}") : "http_{$status}";
            $message = is_array($json) ? (string) ($json['error']['message'] ?? $json['message'] ?? '') : '';
            log_message('error', 'token order {o}: Finance refused the payment link ({c}) {m}', ['o' => $orderRef, 'c' => $code, 'm' => $message]);

            return ['ok' => false, 'link' => null, 'code' => $code, 'message' => $message !== '' ? $message : "Finance answered HTTP {$status}."];
        } catch (GuzzleException $e) {
            log_message('error', 'token order {o}: Finance unreachable: {m}', ['o' => $orderRef, 'm' => $e->getMessage()]);

            return ['ok' => false, 'link' => null, 'code' => 'finance_unreachable', 'message' => 'Finance could not be reached. Nothing has been charged.'];
        }
    }
}

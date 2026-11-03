<?php
declare(strict_types=1);

namespace App\Services\Tokens;

use App\Libraries\AicS2s\AicS2sSignature;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Sends AI Pulse the rate card: what every model's tokens cost a customer.
 *
 * The card is pushed ALREADY RESOLVED — one effective multiplier per model, not the rules
 * that produce it. Three of the four resolution steps (a model rate, a provider default, the
 * model's Console tier, the unclassified fallback) need facts only Engage and Console have, so
 * resolving here means Pulse looks a number up rather than re-deriving one. Two implementations
 * of pricing agree right up until the day they do not, and the day they do not is a customer
 * being charged something other than what Engage showed them.
 *
 * Pulse keeps the last card it was given, so a push that fails changes nothing: the previous
 * card stays in force rather than the estate falling back to defaults. That makes this safe to
 * run on a schedule and safe to retry.
 *
 * Dormant until PULSE_API_BASE_URL and the outbound key are set.
 */
final class TokenRatePusher
{
    public const PATH = '/v1/inbound/engage/token-rate-card';

    /**
     * @param ?\Closure():array{ok:bool, models:list<array<string,mixed>>, error:?string} $catalogue
     *        how the Console model list is read; defaults to ConsoleModelCatalogue. A closure
     *        rather than the class itself so a test can stand in for a Console outage without
     *        that class having to stop being final.
     */
    public function __construct(
        private readonly ?Client $http = null,
        private readonly ?TokenRateService $rates = null,
        private readonly ?\Closure $catalogue = null,
    ) {
    }

    /**
     * The card as Pulse will read it.
     *
     * Every model Console knows gets an entry, so a model bound in Console is never priced by
     * Pulse's own fallback. `fallback_bp` covers only the gap between two pushes — a model
     * registered in Console since this ran.
     *
     * @return array{rates:array<string,int>, provider_defaults:array<string,int>, fallback_bp:int, generated_at:string, models:int, catalogue_available:bool}
     */
    public function card(): array
    {
        $svc = $this->rates ?? new TokenRateService();
        $cat = $this->catalogue !== null ? ($this->catalogue)() : (new ConsoleModelCatalogue())->models();
        $priced = $svc->priceCatalogue($cat['models']);

        $rates = [];
        // Every model Console knows, at whatever it currently resolves to — including the ones
        // priced only by their tier, which Pulse cannot work out for itself.
        foreach ($priced['models'] as $m) {
            $rates[strtolower($m['provider_slug'] . '|' . $m['model_key'])] = (int) $m['multiplier_bp'];
        }

        $defaults = [];
        foreach ($svc->activeRates() as $r) {
            if (($r['model_key'] ?? null) === null) {
                // A provider-wide rate travels on its own, so a model Console gains between
                // pushes is priced by its provider's default rather than the estate fallback.
                $defaults[strtolower((string) $r['provider_code'])] = (int) $r['multiplier_bp'];

                continue;
            }
            // A rate someone set for a specific model is Engage's OWN data and must be on the
            // card whether or not Console answered. Without this, a catalogue outage would drop
            // the model's rate and Pulse would silently price it at the provider default —
            // 25x instead of the 10x somebody deliberately set, or the other way round.
            $rates[strtolower((string) $r['provider_code'] . '|' . (string) $r['model_key'])] = (int) $r['multiplier_bp'];
        }

        $tiers = $svc->tierFallback();

        return [
            'rates'               => $rates,
            'provider_defaults'   => $defaults,
            'fallback_bp'         => (int) ($tiers[$svc->unclassifiedTier()] ?? 100000),
            'generated_at'        => gmdate('c'),
            'models'              => count($rates),
            'catalogue_available' => $cat['ok'],
        ];
    }

    /**
     * Push it. Never throws: a failed push leaves Pulse on the card it already has.
     *
     * @return array{ok:bool, status:int, message:string, models:int}
     */
    public function push(): array
    {
        $card = $this->card();

        // A card built without Console is missing every model priced only by its tier, so
        // pushing it would QUIETLY REPRICE those models down to the estate fallback. Pulse keeps
        // the last card it was given, so doing nothing is the safe answer to a catalogue outage.
        if (! $card['catalogue_available']) {
            return [
                'ok' => false, 'status' => 0, 'models' => $card['models'],
                'message' => 'Console\'s model catalogue is unavailable, so the card would be incomplete. '
                    . 'Nothing sent: Pulse keeps the card it already has.',
            ];
        }

        $base = rtrim((string) env('PULSE_API_BASE_URL', ''), '/');
        $keyId = (string) env('ENGAGE_OUTBOUND_PULSE_KEY_ID', '');
        $secret = (string) env('ENGAGE_OUTBOUND_PULSE_SECRET', '');
        $environment = (string) env('ENGAGE_S2S_ENVIRONMENT', 'production');

        if ($base === '' || $keyId === '' || strlen($secret) < 32) {
            return [
                'ok' => false, 'status' => 0, 'models' => $card['models'],
                'message' => 'Not configured: set PULSE_API_BASE_URL, ENGAGE_OUTBOUND_PULSE_KEY_ID and ENGAGE_OUTBOUND_PULSE_SECRET (>= 32 bytes).',
            ];
        }

        // The body is signed byte for byte, so it is encoded ONCE and that exact string is
        // both hashed and sent. Re-encoding between the two is how a signature stops matching.
        $body = json_encode($card, JSON_UNESCAPED_SLASHES);
        $headers = AicS2sSignature::headers(
            'engage', $keyId, $secret, $environment, 'PUT', self::PATH, '', $body, 'service'
        );

        try {
            $res = ($this->http ?? new Client(['timeout' => 15]))->request('PUT', $base . '/api' . self::PATH, [
                'headers'     => $headers + ['Content-Type' => 'application/json'],
                'body'        => $body,
                'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if (! $ok) {
                $json = json_decode((string) $res->getBody(), true);
                $why = is_array($json) ? (string) ($json['message'] ?? $json['code'] ?? '') : '';
                log_message('error', 'token rate card: Pulse answered HTTP {s} {why}', ['s' => $status, 'why' => $why]);

                return ['ok' => false, 'status' => $status, 'models' => $card['models'],
                    'message' => "Pulse answered HTTP {$status}" . ($why !== '' ? ": {$why}" : '.')];
            }

            return ['ok' => true, 'status' => $status, 'models' => $card['models'], 'message' => 'Accepted.'];
        } catch (GuzzleException $e) {
            log_message('error', 'token rate card: Pulse unreachable: {m}', ['m' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'models' => $card['models'], 'message' => 'Pulse could not be reached: ' . $e->getMessage()];
        }
    }
}

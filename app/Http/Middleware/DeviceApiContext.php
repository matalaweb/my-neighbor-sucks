<?php

namespace App\Http\Middleware;

use App\Http\DeviceApi\DeviceApiResponse;
use App\Models\DeviceCredential;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Assigns request_id/server_received_at, emits a structured access log
 * (no bodies, tokens, or signed URLs), and records request metrics.
 */
class DeviceApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never reuse a credential resolved for an earlier request in the same
        // process (long-lived workers, tests): revocation must apply immediately.
        Auth::guard('device')->forgetUser();

        $requestId = $request->headers->get('X-Request-Id');

        if (! is_string($requestId) || preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId) !== 1) {
            $requestId = (string) Str::uuid7();
        }

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('server_received_at', CarbonImmutable::now());
        DeviceApiResponse::envelope($request);

        $startedAt = hrtime(true);
        $response = $next($request);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('Cache-Control', 'no-store');

        $this->recordMetrics($request, $response, $latencyMs);

        return $response;
    }

    private function recordMetrics(Request $request, Response $response, int $latencyMs): void
    {
        $credential = $request->user('device');
        $deviceId = $credential instanceof DeviceCredential ? $credential->device_id : null;
        $accountId = $credential instanceof DeviceCredential ? $credential->account_id : null;
        $endpoint = $request->route()?->getName() ?? 'device.unknown';
        $rejectedRows = (int) $request->attributes->get('rejected_rows', 0);

        Log::info('device_api_request', [
            'request_id' => $request->attributes->get('request_id'),
            'endpoint' => $endpoint,
            'device_id' => $deviceId,
            'status' => $response->getStatusCode(),
            'latency_ms' => $latencyMs,
            'error_code' => $request->attributes->get('error_code'),
        ]);

        try {
            DB::statement(
                'INSERT INTO api_request_metrics (account_id, device_id, hour, endpoint, status_code, request_count, latency_ms_sum, latency_ms_max, rejected_rows) '
                .'VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?) AS new '
                .'ON DUPLICATE KEY UPDATE request_count = api_request_metrics.request_count + 1, '
                .'latency_ms_sum = api_request_metrics.latency_ms_sum + new.latency_ms_sum, '
                .'latency_ms_max = GREATEST(api_request_metrics.latency_ms_max, new.latency_ms_max), '
                .'rejected_rows = api_request_metrics.rejected_rows + new.rejected_rows',
                [$accountId, $deviceId, CarbonImmutable::now()->startOfHour()->format('Y-m-d H:i:s'), $endpoint, $response->getStatusCode(), $latencyMs, $latencyMs, $rejectedRows],
            );
        } catch (Throwable $exception) {
            // Metrics must never fail a device request.
            report($exception);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\MaintenanceJobStatus;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness: database, cache/queue store, object storage, and outbox lag.
 * Returns 503 when a hard dependency is unavailable. No secrets in output.
 */
class HealthController extends Controller
{
    public function __invoke(EvidenceStorage $storage): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(fn () => Cache::store()->put('health:ready', 1, 10)),
            'object_storage' => $this->check(fn () => $storage->disk()->exists('health/does-not-exist')),
        ];

        $ready = ! in_array(false, array_column($checks, 'ok'), true);

        try {
            $oldest = DB::table('maintenance_jobs')->where('status', MaintenanceJobStatus::Pending->value)->min('created_at');
            $checks['outbox'] = [
                'ok' => true,
                'oldest_pending_age_seconds' => $oldest ? CarbonImmutable::parse($oldest)->diffInSeconds(CarbonImmutable::now()) : 0,
                'failed' => DB::table('maintenance_jobs')->where('status', MaintenanceJobStatus::Failed->value)->count(),
            ];
        } catch (Throwable) {
            $checks['outbox'] = ['ok' => false];
        }

        return new JsonResponse(['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503);
    }

    /**
     * @return array{ok: bool, ms: int}
     */
    private function check(callable $probe): array
    {
        $start = hrtime(true);

        try {
            $probe();
            $ok = true;
        } catch (Throwable $exception) {
            report($exception);
            $ok = false;
        }

        return ['ok' => $ok, 'ms' => (int) ((hrtime(true) - $start) / 1_000_000)];
    }
}

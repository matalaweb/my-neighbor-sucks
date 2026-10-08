<?php

namespace App\Filament\Pages;

use App\Enums\MaintenanceJobStatus;
use App\Enums\RecordingStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\EventRecording;
use App\Models\MaintenanceJob;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;
use UnitEnum;

/**
 * Failed jobs, backlog age, retention state, storage verification errors,
 * ingestion metrics, and recent audit events.
 */
class OperationalStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Operational status';

    protected string $view = 'filament.pages.operational-status';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Account $account */
        $account = Filament::getTenant();
        $since = CarbonImmutable::now()->subDay();

        $queues = [];

        foreach (['rollups', 'recordings', 'exports', 'default'] as $queue) {
            try {
                $queues[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $queues[$queue] = null;
            }
        }

        $metrics = DB::table('api_request_metrics')
            ->where('account_id', $account->id)
            ->where('hour', '>=', $since->startOfHour())
            ->selectRaw('endpoint, SUM(IF(status_code < 400, request_count, 0)) AS ok, SUM(IF(status_code >= 400 AND status_code < 500, request_count, 0)) AS rejected, SUM(IF(status_code >= 500, request_count, 0)) AS failed, SUM(latency_ms_sum) / SUM(request_count) AS avg_ms, MAX(latency_ms_max) AS max_ms, SUM(rejected_rows) AS rejected_rows')
            ->groupBy('endpoint')
            ->get();

        return [
            'devices' => Device::query()->where('account_id', $account->id)->where('status', 'active')->with('latestHeartbeat')->get(),
            'queues' => $queues,
            'outbox' => MaintenanceJob::query()
                ->where('account_id', $account->id)
                ->selectRaw('kind, status, COUNT(*) AS total, MIN(created_at) AS oldest')
                ->groupBy('kind', 'status')
                ->get(),
            'failedMaintenance' => MaintenanceJob::query()->where('account_id', $account->id)->where('status', MaintenanceJobStatus::Failed)->latest('updated_at')->limit(20)->get(),
            'failedJobs' => DB::table('failed_jobs')->latest('failed_at')->limit(10)->get(['uuid', 'queue', 'failed_at', 'exception'])
                ->map(fn ($job) => (object) [...(array) $job, 'exception' => str($job->exception)->before("\n")->limit(200)->toString()]),
            'verificationFailures' => EventRecording::query()->where('account_id', $account->id)->where('status', RecordingStatus::Failed)->with('noiseEvent')->latest('updated_at')->limit(20)->get(),
            'metrics' => $metrics,
            'lastRetention' => Cache::get('noise:retention:last_run'),
            'audit' => AuditLog::query()->where('account_id', $account->id)->with('user', 'device')->latest('id')->limit(30)->get(),
            'timezone' => $account->properties()->value('timezone') ?? config('noise.default_timezone'),
        ];
    }
}

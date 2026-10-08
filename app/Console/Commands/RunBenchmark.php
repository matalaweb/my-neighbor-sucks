<?php

namespace App\Console\Commands;

use App\Enums\CalibrationState;
use App\Enums\MembershipRole;
use App\Enums\Metric;
use App\Models\Account;
use App\Models\Device;
use App\Models\Property;
use App\Models\User;
use App\Services\Devices\DeviceConfigurationService;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\ProvenanceRecords;
use App\Services\Ingestion\ProvenanceResolver;
use App\Services\Measurements\DashboardSummary;
use App\Services\Measurements\MeasurementSeries;
use App\Services\Measurements\QualityPolicy;
use App\Services\Measurements\RebuildRollups;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acceptance benchmark (spec §18). Builds a synthetic benchmark dataset in
 * the configured database and measures the targets in-process through the
 * full HTTP kernel (excluding client network latency).
 *
 * Run against an isolated database, e.g.:
 *   docker compose exec -e DB_DATABASE=noise_monitor_bench app php artisan noise:benchmark
 */
#[Signature('noise:benchmark {--devices=10 : Devices in the benchmark account} {--days=30 : Days of one-second history for the primary device} {--iterations=40 : Timed iterations per measurement} {--force : Allow running against a non-benchmark database name}')]
#[Description('Build a synthetic benchmark dataset and measure ingestion and dashboard latency')]
class RunBenchmark extends Command
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_contains($database, 'bench') && ! $this->option('force')) {
            $this->error("Refusing to write a benchmark dataset into [{$database}]; use DB_DATABASE=noise_monitor_bench or --force.");

            return self::FAILURE;
        }

        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        config(['queue.default' => 'null']);
        // Latency is measured with the per-device rate limits lifted; real-limit
        // backlog recovery time is reported separately from the configured limits.
        $limits = ['per_minute' => (int) config('noise.device_api.measurement_rate_per_minute'), 'burst' => (int) config('noise.device_api.measurement_burst')];
        config(['noise.device_api.measurement_rate_per_minute' => 1_000_000, 'noise.device_api.measurement_burst' => 1_000_000]);
        $now = CarbonImmutable::now()->startOfSecond();
        $results = ['environment' => $this->environment()];

        $this->info('Provisioning benchmark account…');
        [$owner, $account, $devices] = $this->provision((int) $this->option('devices'));
        $primary = $devices[0];

        $this->info("Seeding {$this->option('days')} days of one-second history for the primary device (bulk SQL, dataset only)…");
        $seed = $this->seedHistory($primary, (int) $this->option('days'), $now->subMinutes(30));
        $results['dataset'] = $seed;

        $this->info('Measuring 300-record batch ingestion through the HTTP kernel…');
        $results['ingest_300'] = $this->measureIngest($primary, (int) $this->option('iterations'), $now->subMinutes(29));

        $this->info('Measuring sustained load: '.count($devices).' devices × 30-record batches…');
        $results['sustained'] = $this->measureSustained($devices, $now);

        $this->info('Measuring derived-data latency (commit → minute rollup)…');
        $results['rollup_latency'] = $this->measureRollupLatency($primary, $now);

        $this->info('Measuring 24-hour dashboard render with history present…');
        $results['dashboard'] = $this->measureDashboard($owner, $account, $primary, (int) $this->option('iterations'));

        $this->info('Measuring 24-hour backlog replay with duplicates…');
        $results['backlog'] = $this->measureBacklog($devices[1], $now->subDays(2)->startOfDay());

        $results['backlog']['minimum_minutes_at_configured_rate_limit'] = round(288 / $limits['per_minute'], 1);
        $results['backlog']['configured_rate_limit'] = $limits;

        $this->newLine();
        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return array{0: User, 1: Account, 2: list<Device>}
     */
    private function provision(int $count): array
    {
        $account = Account::query()->create(['name' => 'Benchmark (synthetic) '.Str::random(4)]);
        $owner = User::query()->create(['name' => 'Benchmark owner', 'email' => 'bench-'.Str::random(8).'@example.test', 'password' => Str::random(32), 'current_account_id' => $account->id]);
        $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);
        $property = Property::query()->create(['account_id' => $account->id, 'name' => 'Benchmark property', 'timezone' => 'America/Chicago']);
        $devices = [];

        for ($i = 1; $i <= $count; $i++) {
            $device = Device::query()->create(['account_id' => $account->id, 'property_id' => $property->id, 'name' => "Bench Pi {$i}", 'status' => 'active']);
            $provenance = app(ProvenanceRecords::class);
            $deployment = $provenance->createDeployment($device, ['room' => 'Bench', 'location_type' => 'indoor', 'effective_at' => '2020-01-01T00:00:00Z'], $owner);
            $metrics = array_map(fn (Metric $m): string => $m->value, Metric::cases());
            $profile = $provenance->createProfile($device, [
                'channel' => 'mic-1', 'microphone_model' => 'Benchmark mic', 'sample_rate_hz' => 48000,
                'weighting_implementation_version' => 'bench', 'filter_implementation_version' => 'bench',
                'calibration_state' => CalibrationState::Calibrated, 'supported_metrics' => $metrics,
                'agent_processing_version' => 'bench',
            ], $owner);
            $calibration = $provenance->createCalibration($device, ['channel' => 'mic-1', 'calibration_state' => CalibrationState::Calibrated, 'reference_method' => 'synthetic benchmark'], $owner);
            $settings = app(DeviceConfigurationService::class)->defaults();
            $settings['channels'] = [['channel' => 'mic-1', 'enabled' => true, 'metrics' => $metrics, 'bands_enabled' => false, 'measurement_profile_id' => $profile->uuid, 'deployment_id' => $deployment->uuid, 'calibration_id' => $calibration->uuid]];
            app(DeviceConfigurationService::class)->publish($device, $settings, $owner);
            $this->tokens[$device->uuid] = app(DeviceCredentialService::class)->issue($device, $owner)['token'];
            $device->setRelation('benchProvenance', collect([$deployment, $profile, $calibration]));
            $devices[] = $device->fresh();
        }

        return [$owner, $account, $devices];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedHistory(Device $device, int $days, CarbonImmutable $end): array
    {
        $started = microtime(true);
        $stream = $this->streamFor($device);
        $batchId = DB::table('measurement_batches')->insertGetId([
            'uuid' => (string) Str::uuid(), 'account_id' => $device->account_id, 'device_id' => $device->id, 'schema_version' => 1,
            'payload_hash' => str_repeat('0', 64), 'record_count' => 0, 'received_at' => $end->format('Y-m-d H:i:s.u'),
        ]);
        $boot = (string) Str::uuid();
        $start = $end->subDays($days)->startOfMinute();
        $total = $days * 86400;
        $chunk = 5000;
        $bar = $this->output->createProgressBar(intdiv($total, $chunk));

        for ($offset = 0; $offset < $total; $offset += $chunk) {
            $values = [];

            for ($i = $offset; $i < min($total, $offset + $chunk); $i++) {
                $t = $start->addSeconds($i)->format('Y-m-d H:i:s');
                $hour = (int) $start->addSeconds($i)->setTimezone('America/Chicago')->format('G');
                $laeq = 38 + ($hour >= 7 && $hour <= 22 ? 8 : 0) + (($i * 7919) % 100) / 25;
                $values[] = sprintf("(%d,%d,'mic-1','%s',%d,%d,1,'%s',1000,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,0,UNHEX(SHA2('%d',256)),%d,'%s')",
                    $device->account_id, $device->id, $boot, $i, $stream, $t, $laeq, $laeq + 4, $laeq + 9, $laeq + 25, $laeq + 7, $laeq - 94, $i, $batchId, $t);
            }

            DB::unprepared('INSERT INTO measurements (account_id, device_id, channel, boot_id, sequence, stream_id, configuration_revision, captured_at, duration_ms, laeq_db, lafmax_db, lceq_db, lcpeak_db, low_frequency_leq_db, rms_dbfs, quality_flags, row_hash, batch_id, received_at) VALUES '.implode(',', $values));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Benchmark-only rollup seeding via set-based SQL using the same energy formula.
        $energy = fn (string $c, string $p): string => "SUM(duration_ms * POW(10, {$c} / 10)) AS {$p}_energy_sum, SUM(duration_ms) AS {$p}_valid_ms, 0 AS {$p}_excluded_ms";
        DB::statement("INSERT INTO measurement_rollups (account_id, device_id, stream_id, resolution_seconds, bucket_start, expected_ms, covered_ms, excluded_ms, ambiguous_ms, row_count,
                laeq_energy_sum, laeq_valid_ms, laeq_excluded_ms, lceq_energy_sum, lceq_valid_ms, lceq_excluded_ms, lf_energy_sum, lf_valid_ms, lf_excluded_ms, dbfs_energy_sum, dbfs_valid_ms, dbfs_excluded_ms,
                lafmax_max, lafmax_valid_ms, lafmax_excluded_ms, lcpeak_max, lcpeak_valid_ms, lcpeak_excluded_ms, configuration_revisions, policy_version, rebuilt_at)
            SELECT account_id, device_id, stream_id, 60, DATE_FORMAT(captured_at, '%Y-%m-%d %H:%i:00'), 60000, SUM(duration_ms), 0, 0, COUNT(*),
                {$energy('laeq_db', 'laeq')}, {$energy('lceq_db', 'lceq')}, {$energy('low_frequency_leq_db', 'lf')}, {$energy('rms_dbfs', 'dbfs')},
                MAX(lafmax_db), SUM(duration_ms), 0, MAX(lcpeak_db), SUM(duration_ms), 0, '[1]', '".QualityPolicy::VERSION."', NOW(6)
            FROM measurements WHERE device_id = ? GROUP BY account_id, device_id, stream_id, DATE_FORMAT(captured_at, '%Y-%m-%d %H:%i:00')", [$device->id]);
        DB::statement("INSERT INTO measurement_rollups (account_id, device_id, stream_id, resolution_seconds, bucket_start, expected_ms, covered_ms, excluded_ms, ambiguous_ms, row_count,
                laeq_energy_sum, laeq_valid_ms, laeq_excluded_ms, lceq_energy_sum, lceq_valid_ms, lceq_excluded_ms, lf_energy_sum, lf_valid_ms, lf_excluded_ms, dbfs_energy_sum, dbfs_valid_ms, dbfs_excluded_ms,
                lafmax_max, lafmax_valid_ms, lafmax_excluded_ms, lcpeak_max, lcpeak_valid_ms, lcpeak_excluded_ms, configuration_revisions, policy_version, rebuilt_at)
            SELECT account_id, device_id, stream_id, 3600, DATE_FORMAT(bucket_start, '%Y-%m-%d %H:00:00'), 3600000, SUM(covered_ms), 0, 0, SUM(row_count),
                SUM(laeq_energy_sum), SUM(laeq_valid_ms), 0, SUM(lceq_energy_sum), SUM(lceq_valid_ms), 0, SUM(lf_energy_sum), SUM(lf_valid_ms), 0, SUM(dbfs_energy_sum), SUM(dbfs_valid_ms), 0,
                MAX(lafmax_max), SUM(lafmax_valid_ms), 0, MAX(lcpeak_max), SUM(lcpeak_valid_ms), 0, '[1]', '".QualityPolicy::VERSION."', NOW(6)
            FROM measurement_rollups WHERE device_id = ? AND resolution_seconds = 60 GROUP BY account_id, device_id, stream_id, DATE_FORMAT(bucket_start, '%Y-%m-%d %H:00:00')", [$device->id]);

        DB::table('devices')->where('id', $device->id)->update(['latest_capture_at' => $start->addSeconds($total - 1)->format('Y-m-d H:i:s')]);

        return [
            'raw_rows' => (int) DB::table('measurements')->where('device_id', $device->id)->count(),
            'minute_rollups' => (int) DB::table('measurement_rollups')->where('device_id', $device->id)->where('resolution_seconds', 60)->count(),
            'seed_seconds' => round(microtime(true) - $started, 1),
        ];
    }

    private function streamFor(Device $device): int
    {
        [$deployment, $profile, $calibration] = $device->benchProvenance ?? [null, null, null];
        $deployment ??= $device->deployments()->first();
        $profile ??= $device->measurementProfiles()->first();
        $calibration ??= $device->calibrations()->first();

        return app(ProvenanceResolver::class)->streamIds($device, [0 => ['mic-1', $deployment->id, $profile->id, $calibration->id, CalibrationState::Calibrated]])[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(Device $device, string $boot, int $firstSequence, CarbonImmutable $start, int $count): array
    {
        $deployment = $device->deployments()->first();
        $profile = $device->measurementProfiles()->first();
        $calibration = $device->calibrations()->first();
        $revision = (int) $device->configurations()->max('revision');
        $records = [];

        for ($i = 0; $i < $count; $i++) {
            $laeq = 40 + ($i % 17) * 0.7;
            $records[] = [
                'boot_id' => $boot, 'sequence' => $firstSequence + $i, 'channel' => 'mic-1',
                'captured_at' => $start->addSeconds($i)->format('Y-m-d\TH:i:s.v\Z'), 'duration_ms' => 1000,
                'deployment_id' => $deployment->uuid, 'profile_id' => $profile->uuid, 'calibration_id' => $calibration->uuid,
                'configuration_revision' => $revision,
                'laeq_db' => $laeq, 'lafmax_db' => $laeq + 4.2, 'lceq_db' => $laeq + 9.1, 'lcpeak_db' => $laeq + 24.3,
                'low_frequency_leq_db' => $laeq + 6.5, 'rms_dbfs' => $laeq - 94.0, 'quality_flags' => [], 'bands' => [],
            ];
        }

        return $records;
    }

    private function post(Device $device, string $uri, array $payload): int
    {
        $request = Request::create('/api/v1/device/'.$uri, 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokens[$device->uuid],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($payload));

        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        app('auth')->forgetGuards();

        return $response->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function measureIngest(Device $device, int $iterations, CarbonImmutable $start): array
    {
        $boot = (string) Str::uuid();
        $timings = [];
        $statuses = [];

        for ($i = 0; $i < $iterations; $i++) {
            $payload = ['schema_version' => 1, 'batch_id' => (string) Str::uuid(), 'records' => $this->records($device, $boot, 1 + $i * 300, $start->subDays(3)->addSeconds($i * 300), 300)];
            $t = hrtime(true);
            $statuses[] = $this->post($device, 'measurements/batches', $payload);
            $timings[] = (hrtime(true) - $t) / 1e6;
        }

        return $this->stats($timings) + ['statuses' => array_count_values($statuses), 'records_per_batch' => 300];
    }

    /**
     * @param  list<Device>  $devices
     * @return array<string, mixed>
     */
    private function measureSustained(array $devices, CarbonImmutable $now): array
    {
        $timings = [];
        $boots = [];
        $minutes = 5;
        $wall = hrtime(true);

        // Five simulated minutes: every device sends two 30-record batches per minute.
        for ($round = 0; $round < $minutes * 2; $round++) {
            foreach ($devices as $device) {
                $boots[$device->uuid] ??= (string) Str::uuid();
                $start = $now->subHours(2)->addSeconds($round * 30);
                $payload = ['schema_version' => 1, 'batch_id' => (string) Str::uuid(), 'records' => $this->records($device, $boots[$device->uuid], 1 + $round * 30, $start, 30)];
                $t = hrtime(true);
                $this->post($device, 'measurements/batches', $payload);
                $timings[] = (hrtime(true) - $t) / 1e6;
            }
        }

        $elapsed = (hrtime(true) - $wall) / 1e9;
        $simulated = $minutes * 60;

        return $this->stats($timings) + [
            'devices' => count($devices),
            'simulated_seconds' => $simulated,
            'wall_seconds' => round($elapsed, 2),
            'headroom_factor' => round($simulated / max($elapsed, 0.001), 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function measureRollupLatency(Device $device, CarbonImmutable $now): array
    {
        $boot = (string) Str::uuid();
        $payload = ['schema_version' => 1, 'batch_id' => (string) Str::uuid(), 'records' => $this->records($device, $boot, 1, $now->subMinutes(5)->startOfMinute(), 30)];
        $this->post($device, 'measurements/batches', $payload);
        $t = hrtime(true);
        app(RebuildRollups::class)->processDirty();

        return ['rebuild_after_commit_ms' => round((hrtime(true) - $t) / 1e6, 1), 'note' => 'Latest-reading tile reads raw rows and is visible immediately after commit; charts use rollups rebuilt by the rollups worker (woken after commit, plus a 60 s reconciler) and refresh on the 15 s poll.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function measureDashboard(User $owner, Account $account, Device $device, int $iterations): array
    {
        $timings = [];
        $statuses = [];
        $date = CarbonImmutable::now('America/Chicago')->subDay()->format('Y-m-d');

        for ($i = 0; $i < $iterations; $i++) {
            $request = Request::create("/app/{$account->uuid}?date={$date}&device={$device->uuid}", 'GET');
            Auth::guard('web')->setUser($owner);
            $t = hrtime(true);
            $response = app(Kernel::class)->handle($request);
            $timings[] = (hrtime(true) - $t) / 1e6;
            $statuses[] = $response->getStatusCode();
            app(Kernel::class)->terminate($request, $response);
        }

        [$from, $to] = LocalTime::dayRange($date, 'America/Chicago');
        $series = app(MeasurementSeries::class)->forDevice($device, 'mic-1', $from, $to, Metric::LAeq);
        $t = hrtime(true);
        app(DashboardSummary::class)->device($device, $date);
        $summaryMs = (hrtime(true) - $t) / 1e6;

        return $this->stats($timings) + [
            'statuses' => array_count_values($statuses),
            'points_per_series' => count($series['series'][0]['points'] ?? []),
            'resolution_seconds' => $series['resolution']['seconds'],
            'summary_query_ms' => round($summaryMs, 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function measureBacklog(Device $device, CarbonImmutable $dayStart): array
    {
        $boot = (string) Str::uuid();
        $batches = [];

        for ($b = 0; $b < 288; $b++) {
            $batches[] = ['schema_version' => 1, 'batch_id' => (string) Str::uuid(), 'records' => $this->records($device, $boot, 1 + $b * 300, $dayStart->addSeconds($b * 300), 300)];
        }

        $wall = hrtime(true);
        $timings = [];

        foreach ($batches as $index => $payload) {
            $t = hrtime(true);
            $this->post($device, 'measurements/batches', $payload);
            $timings[] = (hrtime(true) - $t) / 1e6;

            // Lost acknowledgments: every tenth batch is retried.
            if ($index % 10 === 0) {
                $this->post($device, 'measurements/batches', $payload);
            }
        }

        $wallSeconds = (hrtime(true) - $wall) / 1e9;
        $rows = (int) DB::table('measurements')->where('device_id', $device->id)->where('boot_id', $boot)->count();

        return $this->stats($timings) + [
            'batches' => 288,
            'rows_expected' => 86400,
            'rows_stored' => $rows,
            'duplicates_created' => $rows - 86400,
            'wall_seconds' => round($wallSeconds, 1),
        ];
    }

    /**
     * @param  list<float>  $values
     * @return array<string, float|int>
     */
    private function stats(array $values): array
    {
        sort($values);
        $count = count($values);
        $pick = fn (float $q): float => round($values[(int) min($count - 1, ceil($q * $count) - 1)], 1);

        return ['n' => $count, 'p50_ms' => $pick(0.5), 'p95_ms' => $pick(0.95), 'max_ms' => round(max($values), 1)];
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'mysql' => (string) DB::scalar('select version()'),
            'host' => php_uname('m').' '.php_uname('s'),
            'cpus' => (string) (int) shell_exec('nproc 2>/dev/null'),
            'opcache_cli' => ini_get('opcache.enable_cli') ? 'on' : 'off',
            'run_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ];
    }
}

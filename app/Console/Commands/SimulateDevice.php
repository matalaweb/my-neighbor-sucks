<?php

namespace App\Console\Commands;

use App\Support\CanonicalJson;
use App\Support\SyntheticAudio;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Device simulator that exercises the PUBLIC device API only (no direct
 * database access). Like the real agent, it registers its own measurement
 * chain (POST /provenance) and runs on local defaults until a configuration
 * is published. All acoustic patterns are SYNTHETIC: they test the
 * pipeline and never validate source classification.
 */
#[Signature('noise:simulate
    {--token= : Device bearer token (or NOISE_SIM_TOKEN)}
    {--base-url=http://localhost:8080 : Application base URL}
    {--scenario=* : background, burst, garage, missing-audio, clipping, uncalibrated, offline-replay, duplicates, clock, delayed-event, delayed-recording, day, all}
    {--date= : UTC date for the "day" scenario (default: yesterday)}
    {--hours=24 : Hours to replay for the "day" scenario}
    {--seed=42 : Random seed}
    {--speed=1 : Real-time divisor for simulated delays (0 = no waiting)}
    {--with-uncalibrated : Also register and use an uncalibrated mic-2 channel (dBFS only)}
    {--no-verify-wait : Do not poll recordings until verified}')]
#[Description('Simulate a Raspberry Pi capture agent against the device API with synthetic data')]
class SimulateDevice extends Command
{
    private const ALL = ['background', 'burst', 'garage', 'missing-audio', 'clipping', 'uncalibrated', 'offline-replay', 'duplicates', 'clock', 'delayed-event', 'delayed-recording'];

    /** Seconds of capture timeline each scenario occupies (scenarios never overlap). */
    private const DURATIONS = [
        'background' => 300, 'burst' => 120, 'garage' => 120, 'missing-audio' => 120, 'clipping' => 120,
        'uncalibrated' => 120, 'offline-replay' => 1800, 'duplicates' => 120, 'clock' => 60,
        'delayed-event' => 120, 'delayed-recording' => 120, 'day' => 0,
    ];

    private const METRICS = ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'];

    private const UNCALIBRATED_CHANNEL = 'mic-2';

    private string $bootId;

    private int $sequence = 0;

    /** Applied configuration revision; null while the device runs on local defaults. */
    private ?int $configurationRevision = null;

    /** @var array<string, mixed> */
    private array $channel = [];

    /** @var array<string, mixed>|null */
    private ?array $uncalibratedChannel = null;

    /** @var array<string, int> */
    private array $stats = ['requests' => 0, 'batches' => 0, 'inserted' => 0, 'duplicates' => 0, 'replayed_batches' => 0, 'events' => 0, 'recordings_verified' => 0, 'recordings_failed' => 0, 'recordings_unverified' => 0];

    /** @var array<string, int> */
    private array $rejections = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $token = $this->option('token') ?: env('NOISE_SIM_TOKEN');

        if (! $token) {
            $this->error('Provide --token (see php artisan noise:demo:provision).');

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));
        $this->bootId = (string) Str::uuid();
        $scenarios = $this->option('scenario') ?: ['background'];

        if (in_array('all', $scenarios, true)) {
            $scenarios = array_values(array_unique([...self::ALL, ...array_diff($scenarios, ['all'])]));
        }

        $this->components->warn('SYNTHETIC SIMULATION: generated acoustic patterns are artificial and do not validate source classification.');

        try {
            $this->loadConfiguration($token);
            $this->registerProvenance($token);
            $this->heartbeat($token);

            // Lay scenarios out back-to-back on one timeline ending now, so the
            // same boot never reports two readings for one interval.
            $cursor = CarbonImmutable::now()->startOfSecond()->subSeconds(5 + array_sum(array_map(fn (string $scenario): int => self::DURATIONS[$scenario] ?? 0, $scenarios)));

            foreach ($scenarios as $scenario) {
                $start = $cursor;
                $this->components->task('Scenario: '.$scenario, fn () => $this->runScenario($token, $scenario, $start) ?? true);
                $cursor = $cursor->addSeconds(self::DURATIONS[$scenario] ?? 0);
            }

            $this->heartbeat($token);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $this->summary();

            return self::FAILURE;
        }

        $this->summary();

        return self::SUCCESS;
    }

    private function runScenario(string $token, string $scenario, CarbonImmutable $start): void
    {
        match ($scenario) {
            'background' => $this->sendRecords($token, $this->generate($start, 300, 'background')),
            'burst' => $this->eventScenario($token, $start, 'vehicle', withRecording: true),
            'garage' => $this->eventScenario($token, $start, 'garage', withRecording: true),
            'missing-audio' => $this->eventScenario($token, $start, 'vehicle', withRecording: false),
            'clipping' => $this->eventScenario($token, $start, 'vehicle', withRecording: false, clipping: true),
            'uncalibrated' => $this->uncalibratedScenario($token, $start),
            'offline-replay' => $this->offlineReplayScenario($token, $start),
            'duplicates' => $this->duplicateScenario($token, $start),
            'clock' => $this->clockScenario($token, $start),
            'delayed-event' => $this->eventScenario($token, $start, 'vehicle', withRecording: false, delayEvent: true),
            'delayed-recording' => $this->eventScenario($token, $start, 'garage', withRecording: true, delayRecording: true),
            'day' => $this->dayScenario($token),
            default => throw new RuntimeException("Unknown scenario {$scenario}."),
        };
    }

    // ---------------------------------------------------------------- scenarios

    private function eventScenario(string $token, CarbonImmutable $windowStart, string $pattern, bool $withRecording, bool $clipping = false, bool $delayEvent = false, bool $delayRecording = false): void
    {
        $eventStart = $windowStart->addSeconds(50);
        $eventDuration = $pattern === 'garage' ? 15 : mt_rand(8, 12);
        $records = $this->generate($windowStart, 120, 'background', [[50, $eventDuration, $pattern]], $clipping);

        if (! $delayEvent) {
            $this->sendRecords($token, array_slice($records, 0, 55));
        }

        $this->postEventAndRecording($token, $eventStart, $eventDuration, $pattern, $records, $withRecording, $delayRecording, $delayEvent ? fn () => $this->sendRecords($token, $records) : fn () => $this->sendRecords($token, array_slice($records, 55)));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function postEventAndRecording(string $token, CarbonImmutable $eventStart, int $durationSeconds, string $pattern, array $records, bool $withRecording, bool $delayRecording, ?\Closure $between = null): void
    {
        $eventId = (string) Str::uuid();
        $eventEnd = $eventStart->addSeconds($durationSeconds);
        $clipSeconds = min($durationSeconds + 40, 60);
        $recordingStart = $eventStart->subSeconds(10);
        $peak = $this->peakOf($records, $eventStart, $eventEnd);

        if ($between === null) {
            $this->postEvent($token, $eventId, 1, $eventStart, null, $pattern, $peak, $withRecording, $recordingStart, null);
        } else {
            $this->postEvent($token, $eventId, 1, $eventStart, null, $pattern, $peak, $withRecording, $recordingStart, null);
            $between();
        }

        $this->postEvent($token, $eventId, 2, $eventStart, $eventEnd, $pattern, $peak, $withRecording, $recordingStart, $recordingStart->addSeconds($clipSeconds));
        $this->stats['events']++;

        if (! $withRecording) {
            return;
        }

        if ($delayRecording) {
            $this->pause(5);
        }

        $this->uploadRecording($token, $eventId, $recordingStart, $clipSeconds, $pattern);
    }

    private function uncalibratedScenario(string $token, CarbonImmutable $start): void
    {
        if ($this->uncalibratedChannel !== null) {
            $records = $this->generate($start, 120, 'background', channel: $this->uncalibratedChannel);
            $this->sendRecords($token, $records);
            $this->check('uncalibrated', 'dBFS-only readings accepted on '.$this->uncalibratedChannel['channel'], 'ok');

            return;
        }

        $record = $this->generate($start, 1, 'background')[0];
        $record['calibration_id'] = (string) Str::uuid();
        $response = $this->postBatch($token, $this->batch([$record]), expectFailure: true);
        $this->check('uncalibrated', 'unknown calibration reference rejected', $response->json('error.code') === 'unknown_provenance' ? 'ok (unknown_provenance)' : 'UNEXPECTED '.$response->status());
    }

    private function offlineReplayScenario(string $token, CarbonImmutable $start): void
    {
        // The device was offline for 30 minutes and queued its batches locally.
        $records = $this->generate($start, 1800, 'background', [[600, 10, 'vehicle'], [1300, 15, 'garage']]);
        $batches = array_map(fn (array $chunk): array => $this->batch($chunk), array_chunk($records, 300));

        // First upload attempt "loses" the acknowledgement of batch 2, then the backlog is replayed out of order.
        $this->postBatch($token, $batches[1]);
        foreach (array_reverse($batches) as $batch) {
            $this->postBatch($token, $batch);
        }

        $this->check('offline-replay', 'backlog of 1,800 readings replayed out of order', 'ok');
    }

    private function duplicateScenario(string $token, CarbonImmutable $start): void
    {
        $records = $this->generate($start, 120, 'background');
        $batch = $this->batch(array_slice($records, 0, 80));

        $this->postBatch($token, $batch);
        $retry = $this->postBatch($token, [...$batch, 'sent_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z')]);
        $this->check('duplicates', 'exact retry returns original result', $retry->status() === 200 && $retry->json('replayed') ? 'ok (200 replayed)' : 'UNEXPECTED '.$retry->status());

        $overlap = $this->postBatch($token, $this->batch(array_slice($records, 40, 80)));
        $this->check('duplicates', 'overlapping batch deduplicated', $overlap->json('duplicate_count') === 40 ? 'ok (40 duplicates)' : 'UNEXPECTED '.json_encode($overlap->json()));

        $changed = $batch;
        $changed['records'][5]['laeq_db'] = ($changed['records'][5]['laeq_db'] ?? 40) + 3.3;
        $conflict = $this->postBatch($token, $changed, expectFailure: true);
        $this->check('duplicates', 'changed payload under same batch id', $conflict->status() === 409 ? 'ok (409 '.$conflict->json('error.code').')' : 'UNEXPECTED '.$conflict->status());
    }

    private function clockScenario(string $token, CarbonImmutable $start): void
    {
        $records = $this->generate($start, 60, 'background', flags: ['unsynchronized_clock']);
        $this->sendRecords($token, $records);
        $this->heartbeat($token, ['sync_state' => 'unsynchronized', 'offset_ms' => 7350]);

        $future = $this->generate(CarbonImmutable::now()->startOfSecond()->addMinutes(6), 10, 'background');
        $response = $this->postBatch($token, $this->batch($future), expectFailure: true);
        $this->check('clock', 'readings 6 minutes in the future rejected', $response->json('error.code') === 'clock_future_timestamp' ? 'ok (clock_future_timestamp; quarantined locally)' : 'UNEXPECTED '.$response->status());
        $this->heartbeat($token);
    }

    private function dayScenario(string $token): void
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date'), 'UTC') : CarbonImmutable::yesterday('UTC');
        $start = $date->startOfDay();
        $seconds = min(86_400, max(1, (int) $this->option('hours')) * 3600);
        $end = $start->addSeconds($seconds);

        if ($end->greaterThan(CarbonImmutable::now())) {
            throw new RuntimeException('The day scenario must replay a past period (choose an earlier --date).');
        }

        // Synthetic disturbances roughly every 45 minutes during the daytime.
        $events = [];

        for ($t = 7 * 3600 + mt_rand(0, 1800); $t < $seconds - 120; $t += 2700 + mt_rand(-600, 600)) {
            $events[] = [$t, mt_rand(0, 3) === 0 ? 15 : mt_rand(6, 14), mt_rand(0, 3) === 0 ? 'garage' : 'vehicle'];
        }

        $bar = $this->output->createProgressBar(intdiv($seconds, 300) + 1);
        $chunk = 3600;

        for ($offset = 0; $offset < $seconds; $offset += $chunk) {
            $length = min($chunk, $seconds - $offset);
            $local = array_values(array_map(fn (array $e): array => [$e[0] - $offset, $e[1], $e[2]], array_filter($events, fn (array $e): bool => $e[0] >= $offset && $e[0] < $offset + $length)));
            $records = $this->generate($start->addSeconds($offset), $length, 'background', $local);

            foreach (array_chunk($records, 300) as $batch) {
                $this->postBatch($token, $this->batch($batch));
                $bar->advance();
            }

            foreach ($local as $index => [$at, $duration, $pattern]) {
                $withRecording = $index % 2 === 0;
                $this->postEventAndRecording($token, $start->addSeconds($offset + $at), $duration, $pattern, $records, $withRecording, false);
            }
        }

        $bar->finish();
        $this->newLine();
        $this->check('day', sprintf('replayed %s readings for %s UTC with %d synthetic events', number_format($seconds), $start->toDateString(), count($events)), 'ok');
    }

    // ---------------------------------------------------------------- signal model

    /**
     * Synthetic one-second readings. $bursts are [offset_seconds, duration_seconds, pattern].
     *
     * @param  list<array{0: int, 1: int, 2: string}>  $bursts
     * @param  list<string>  $flags
     * @param  array<string, mixed>|null  $channel
     * @return list<array<string, mixed>>
     */
    private function generate(CarbonImmutable $start, int $count, string $pattern, array $bursts = [], bool $clipping = false, array $flags = [], ?array $channel = null): array
    {
        $channel ??= $this->channel;
        $records = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $start->addSeconds($i);
            $hour = (int) $at->format('G');
            $laeq = 38 + 6 * sin(($hour - 6) / 24 * 2 * M_PI) + $this->noise(2.0);
            $lowFrequencyBoost = 6.0;
            $active = false;

            foreach ($bursts as [$offset, $duration, $burstPattern]) {
                if ($i >= $offset && $i < $offset + $duration) {
                    $shape = sin(M_PI * ($i - $offset + 0.5) / $duration);
                    $laeq = max($laeq, ($burstPattern === 'garage' ? 60.0 : 76.0) * (0.85 + 0.15 * $shape) + $this->noise(1.0));
                    $lowFrequencyBoost = $burstPattern === 'garage' ? 9.0 : 12.0;
                    $active = true;
                }
            }

            $values = [
                'laeq_db' => round($laeq, 2),
                'lafmax_db' => round($laeq + 3.5 + abs($this->noise(2.0)), 2),
                'lceq_db' => round($laeq + 7.0 + $this->noise(1.0), 2),
                'lcpeak_db' => round($laeq + 22.0 + abs($this->noise(3.0)), 2),
                'low_frequency_leq_db' => round($laeq + $lowFrequencyBoost + $this->noise(1.0), 2),
                'rms_dbfs' => round(min(0.0, $laeq - 104.0), 2),
            ];

            $recordFlags = $flags;

            if ($clipping && $active && $laeq > 70) {
                $recordFlags[] = 'clipping';
            }

            $record = [
                'boot_id' => $this->bootId,
                'sequence' => ++$this->sequence,
                'channel' => $channel['channel'],
                'captured_at' => $at->format('Y-m-d\TH:i:s.v\Z'),
                'duration_ms' => 1000,
                'profile_id' => $channel['profile_id'],
                'calibration_id' => $channel['calibration_id'],
                'configuration_revision' => $this->configurationRevision,
                'quality_flags' => array_values(array_unique($recordFlags)),
                'bands' => [],
            ];

            foreach (self::METRICS as $metric) {
                $record[$metric] = in_array($metric, $channel['supported_metrics'], true) ? $values[$metric] : null;
            }

            $records[] = $record;
        }

        return $records;
    }

    private function noise(float $amplitude): float
    {
        return (mt_rand() / mt_getrandmax() * 2 - 1) * $amplitude;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function peakOf(array $records, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $peak = null;

        foreach ($records as $record) {
            $at = CarbonImmutable::parse($record['captured_at']);

            if ($at->greaterThanOrEqualTo($from) && $at->lessThan($to) && $record['lafmax_db'] !== null) {
                $peak = max($peak ?? -INF, $record['lafmax_db']);
            }
        }

        return $peak ?? 80.0;
    }

    // ---------------------------------------------------------------- API calls

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->option('base-url'), '/').'/api/v1/device')
            ->withToken($token)
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * Send with the agent retry policy: retry network errors, 429 (Retry-After),
     * and 5xx with exponential backoff + jitter using the same identities.
     */
    private function send(string $token, string $method, string $uri, ?array $payload = null): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->stats['requests']++;
                $response = $method === 'GET' ? $this->client($token)->get($uri) : $this->client($token)->post($uri, $payload ?? []);
            } catch (ConnectionException $exception) {
                if ($attempt >= 6) {
                    throw new RuntimeException('Network failure: '.$exception->getMessage());
                }

                usleep((int) (min(30, 2 ** $attempt) * 1_000_000 * (0.5 + mt_rand() / mt_getrandmax() / 2)));

                continue;
            }

            if ($response->status() === 429 && $attempt < 20) {
                sleep(max(1, (int) $response->header('Retry-After')));

                continue;
            }

            if ($response->serverError() && $attempt < 6) {
                usleep((int) (min(30, 2 ** $attempt) * 1_000_000 * (0.5 + mt_rand() / mt_getrandmax() / 2)));

                continue;
            }

            if ($response->failed()) {
                $code = (string) ($response->json('error.code') ?? 'http_'.$response->status());
                $this->rejections[$code] = ($this->rejections[$code] ?? 0) + 1;
            }

            return $response;
        }
    }

    /**
     * The agent measures before any configuration is published (local defaults,
     * configuration_revision null) and only takes operational settings from it.
     */
    private function loadConfiguration(string $token): void
    {
        $response = $this->send($token, 'GET', 'configuration');

        if ($response->status() === 404) {
            $this->line('No configuration published yet; running on local defaults (configuration_revision null).');

            return;
        }

        if ($response->failed()) {
            throw new RuntimeException('Could not fetch configuration: HTTP '.$response->status().' '.$response->json('error.message'));
        }

        $this->configurationRevision = (int) $response->json('revision');
        $configuredChannels = collect($response->json('configuration.channels'))->where('enabled', true)->pluck('channel')->all();

        if (in_array(self::UNCALIBRATED_CHANNEL, $configuredChannels, true)) {
            $this->input->setOption('with-uncalibrated', true);
        }
    }

    /**
     * Register this device's (synthetic) measurement chain, as a real agent does
     * before sending readings that reference it. IDs are derived from the token and
     * the record content, so repeated runs against the same device are idempotent.
     */
    private function registerProvenance(string $token): void
    {
        $namespace = 'noise-simulator:'.hash('sha256', $token).':';
        $profile = [
            'channel' => 'mic-1',
            'name' => 'Simulated calibrated channel',
            'microphone_model' => 'SIMULATED measurement microphone',
            'microphone_serial' => 'SIM-0001',
            'audio_interface' => null,
            'sample_rate_hz' => 48000,
            'gain_db' => 0,
            'gain_description' => null,
            'weighting_implementation_version' => 'simulator-weighting-1',
            'filter_implementation_version' => 'simulator-filters-1',
            'agent_processing_version' => 'simulator-1.0',
            'calibration_state' => 'calibrated',
            'calibration_application_method' => 'Synthetic offset (simulator)',
            'supported_metrics' => self::METRICS,
            'low_frequency_lower_hz' => 20,
            'low_frequency_upper_hz' => 125,
            'band_centers_hz' => [],
        ];
        $calibration = [
            'channel' => 'mic-1',
            'calibration_state' => 'calibrated',
            'reference_method' => 'Synthetic reference (simulator; not a real calibration)',
            'reference_device' => null,
            'reference_level_db' => 94,
            'reference_frequency_hz' => 1000,
            'sensitivity_mv_per_pa' => null,
            'sensitivity_dbfs_at_94db' => -30.0,
            'gain_configuration' => null,
            'application_method' => 'Synthetic offset (simulator)',
            'performed_at' => null,
            'performed_by' => null,
            'notes' => 'Demo data only.',
            'attachments' => [],
        ];
        $uncalibrated = [
            ...$profile,
            'channel' => self::UNCALIBRATED_CHANNEL,
            'name' => 'Simulated uncalibrated channel',
            'microphone_model' => 'SIMULATED USB microphone',
            'microphone_serial' => null,
            'gain_db' => null,
            'calibration_state' => 'uncalibrated',
            'calibration_application_method' => null,
            'supported_metrics' => ['rms_dbfs'],
        ];
        $id = fn (string $kind, array $record): string => Uuid::uuid5(Uuid::NAMESPACE_URL, $namespace.$kind.':'.CanonicalJson::hash($record))->toString();
        $profiles = [['id' => $id('profile', $profile), ...$profile]];

        if ($this->option('with-uncalibrated')) {
            $profiles[] = ['id' => $id('profile', $uncalibrated), ...$uncalibrated];
        }

        $calibrationId = $id('calibration', $calibration);
        $response = $this->send($token, 'POST', 'provenance', [
            'schema_version' => 1,
            'sent_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z'),
            'measurement_profiles' => $profiles,
            'calibrations' => [['id' => $calibrationId, ...$calibration]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Could not register provenance: HTTP '.$response->status().' '.$response->json('error.code').' '.$response->json('error.message'));
        }

        $this->channel = ['channel' => 'mic-1', 'profile_id' => $profiles[0]['id'], 'calibration_id' => $calibrationId, 'calibration_state' => 'calibrated', 'supported_metrics' => self::METRICS];

        if (isset($profiles[1])) {
            $this->uncalibratedChannel = ['channel' => self::UNCALIBRATED_CHANNEL, 'profile_id' => $profiles[1]['id'], 'calibration_id' => null, 'calibration_state' => 'uncalibrated', 'supported_metrics' => ['rms_dbfs']];
        }

        $this->line(sprintf(
            'Provenance %s · configuration %s · channel %s (%s) · boot %s',
            collect($response->json('measurement_profiles'))->pluck('status')->implode('/'),
            $this->configurationRevision === null ? 'local defaults' : 'r'.$this->configurationRevision,
            $this->channel['channel'],
            $this->channel['calibration_state'],
            $this->bootId,
        ));
    }

    /**
     * @param  array{sync_state: string, offset_ms: int|null}|null  $clock
     */
    private function heartbeat(string $token, ?array $clock = null): void
    {
        $channels = array_values(array_filter([$this->channel['channel'] ?? null, $this->uncalibratedChannel['channel'] ?? null]));

        $response = $this->send($token, 'POST', 'heartbeat', [
            'schema_version' => 1,
            'sent_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z'),
            'agent_version' => 'simulator-1.0 (synthetic)',
            'boot_id' => $this->bootId,
            'uptime_seconds' => 120,
            'capabilities' => [
                'channels' => $channels,
                'metrics' => self::METRICS,
                'third_octave_bands' => false,
                'recording_formats' => ['audio/wav', 'audio/flac'],
                'max_sample_rate_hz' => 48000,
            ],
            'microphone_state' => 'ok',
            'free_disk_bytes' => 24_000_000_000,
            'total_disk_bytes' => 32_000_000_000,
            'queued_measurement_count' => 0,
            'pending_audio_bytes' => 0,
            'pending_audio_count' => 0,
            'oldest_pending_capture_at' => null,
            'desired_config_revision' => $this->configurationRevision,
            'applied_config_revision' => $this->configurationRevision,
            'clock' => $clock ?? ['sync_state' => 'synchronized', 'offset_ms' => 2],
            'recent_dropped_intervals' => 0,
            'last_capture_error' => null,
        ]);

        if ($this->configurationRevision !== null && $response->successful() && $response->json('applied_config_revision') !== $this->configurationRevision) {
            $this->send($token, 'POST', 'configuration/acknowledgments', [
                'schema_version' => 1,
                'revision' => $this->configurationRevision,
                'status' => 'applied',
                'applied_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function sendRecords(string $token, array $records): void
    {
        foreach (array_chunk($records, 300) as $chunk) {
            $this->postBatch($token, $this->batch($chunk));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function batch(array $records): array
    {
        return [
            'schema_version' => 1,
            'batch_id' => (string) Str::uuid(),
            'sent_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z'),
            'records' => $records,
        ];
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    private function postBatch(string $token, array $batch, bool $expectFailure = false): Response
    {
        $response = $this->send($token, 'POST', 'measurements/batches', $batch);
        $this->stats['batches']++;

        if ($response->successful()) {
            $this->stats['inserted'] += (int) $response->json('inserted_count');
            $this->stats['duplicates'] += (int) $response->json('duplicate_count');
            $this->stats['replayed_batches'] += $response->json('replayed') ? 1 : 0;
        } elseif (! $expectFailure) {
            $this->warn(sprintf('  batch rejected: %d %s — %s (agent would quarantine it locally)', $response->status(), $response->json('error.code'), $response->json('error.message')));
        }

        return $response;
    }

    private function postEvent(string $token, string $eventId, int $revision, CarbonImmutable $start, ?CarbonImmutable $end, string $pattern, float $peak, bool $withRecording, CarbonImmutable $recordingStart, ?CarbonImmutable $recordingEnd): void
    {
        $absolute = $this->channel['calibration_state'] !== 'uncalibrated';

        $response = $this->send($token, 'POST', 'events', [
            'schema_version' => 1,
            'event_id' => $eventId,
            'revision' => $revision,
            'channel' => $this->channel['channel'],
            'profile_id' => $this->channel['profile_id'],
            'calibration_id' => $this->channel['calibration_id'],
            'configuration_revision' => $this->configurationRevision,
            'detection_state' => $end === null ? 'open' : 'finalized',
            'started_at' => $start->format('Y-m-d\TH:i:s.v\Z'),
            'ended_at' => $end?->format('Y-m-d\TH:i:s.v\Z'),
            'detection' => [
                'rule_version' => 'simulator-rules-1 (synthetic '.$pattern.')',
                'trigger_metric' => $absolute ? 'lafmax_db' : 'rms_dbfs',
                'trigger_kind' => 'baseline_relative',
                'threshold_db' => 15.0,
                'trigger_value_db' => $absolute ? round($peak, 1) : round($peak - 104, 1),
                'baseline_db' => $absolute ? 42.0 : -62.0,
                'baseline_method' => 'simulated rolling 5-minute LAeq median',
            ],
            'summary' => [
                'laeq_db' => $absolute && $end ? round($peak - 6, 1) : null,
                'lafmax_db' => $absolute && $end ? round($peak, 1) : null,
                'lceq_db' => null,
                'lcpeak_db' => null,
                'low_frequency_leq_db' => null,
                'rms_dbfs' => null,
                'duration_ms' => $end ? (int) ($end->getTimestampMs() - $start->getTimestampMs()) : null,
            ],
            'recording' => [
                'expected' => $withRecording || $pattern === 'vehicle',
                'started_at' => $recordingStart->format('Y-m-d\TH:i:s.v\Z'),
                'ended_at' => $recordingEnd?->format('Y-m-d\TH:i:s.v\Z'),
                'expected_segments' => 1,
            ],
            'quality_flags' => [],
        ]);

        if ($response->failed()) {
            $this->warn(sprintf('  event rejected: %d %s', $response->status(), $response->json('error.code')));
        }
    }

    private function uploadRecording(string $token, string $eventId, CarbonImmutable $captureStart, int $seconds, string $pattern): void
    {
        $bytes = SyntheticAudio::wav($seconds * 1000, 8000, $pattern, mt_rand());
        $recordingId = (string) Str::uuid();

        $declared = $this->send($token, 'POST', 'events/'.$eventId.'/recordings', [
            'schema_version' => 1,
            'recording_id' => $recordingId,
            'segment_number' => 1,
            'capture_started_at' => $captureStart->format('Y-m-d\TH:i:s.v\Z'),
            ...SyntheticAudio::describe($bytes, $seconds * 1000),
        ]);

        if ($declared->failed()) {
            $this->warn('  recording declaration rejected: '.$declared->json('error.code'));

            return;
        }

        $upload = $declared->json('upload');
        $put = Http::withHeaders((array) ($upload['headers'] ?? []))->withBody($bytes, 'audio/wav')->timeout(120)->send('PUT', $upload['url']);

        if ($put->failed()) {
            $this->warn('  storage upload failed: HTTP '.$put->status());
            $this->stats['recordings_failed']++;

            return;
        }

        $this->send($token, 'POST', 'recordings/'.$recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $upload['attempt_id']]);

        if ($this->option('no-verify-wait')) {
            $this->stats['recordings_unverified']++;

            return;
        }

        // The agent keeps its local clip until the server reports "verified".
        $deadline = microtime(true) + 90;

        while (microtime(true) < $deadline) {
            $status = $this->send($token, 'GET', 'recordings/'.$recordingId)->json('status');

            if ($status === 'verified') {
                $this->stats['recordings_verified']++;

                return;
            }

            if ($status === 'failed') {
                $this->stats['recordings_failed']++;

                return;
            }

            usleep(1_000_000);
        }

        $this->stats['recordings_unverified']++;
        $this->warn('  recording not verified within 90 s (are queue workers running?); local copy retained.');
    }

    private function pause(int $seconds): void
    {
        $speed = (float) $this->option('speed');

        if ($speed > 0) {
            usleep((int) ($seconds / $speed * 1_000_000));
        }
    }

    private function check(string $scenario, string $what, string $result): void
    {
        $this->checks[] = [$scenario, $what, $result];
    }

    private function summary(): void
    {
        $this->newLine();
        $this->table(['Metric', 'Value'], collect($this->stats)->map(fn (int $value, string $key): array => [$key, number_format($value)])->values()->all());

        if ($this->rejections !== []) {
            $this->table(['Rejection code', 'Count'], collect($this->rejections)->map(fn (int $count, string $code): array => [$code, $count])->values()->all());
        }

        if ($this->checks !== []) {
            $this->table(['Scenario', 'Check', 'Result'], $this->checks);
        }

        $this->line('All acoustic data above is SYNTHETIC.');
    }
}

<?php

use App\Http\DeviceApi\DeviceApiException;
use App\Services\Ingestion\IngestMeasurements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

/*
| Real concurrency against MySQL: child processes (pcntl_fork) each open their
| own connection and ingest at the same moment. Data is committed, so this
| suite does not use RefreshDatabase and truncates afterwards.
*/

beforeEach(function (): void {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('pcntl is required.');
    }

    if (! Schema::hasTable('measurements')) {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    $this->fixture = DeviceFixture::create();
    $this->start = CarbonImmutable::now()->subMinutes(2)->startOfMinute();
});

afterEach(function (): void {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    foreach (DB::select('SHOW TABLES') as $table) {
        $name = array_values((array) $table)[0];

        if ($name !== 'migrations') {
            DB::table($name)->truncate();
        }
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

/**
 * Run each payload in its own forked process released from a shared barrier.
 *
 * @param  list<array<string, mixed>>  $payloads
 * @return list<string> outcome per child: inserted | replayed | <error code>
 */
function ingestConcurrently(DeviceFixture $fixture, array $payloads): array
{
    $dir = sys_get_temp_dir().'/nm-concurrency-'.Str::random(8);
    mkdir($dir);
    $barrier = $dir.'/go';

    DB::disconnect();
    $pids = [];

    foreach ($payloads as $index => $payload) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            while (! file_exists($barrier)) {
                usleep(500);
            }

            try {
                $result = app(IngestMeasurements::class)->handle($fixture->device->fresh(), $payload, CarbonImmutable::now(), 'child-'.$index);
                $outcome = $result->replayed ? 'replayed' : 'inserted';
            } catch (DeviceApiException $exception) {
                $outcome = $exception->errorCode->value;
            } catch (Throwable $exception) {
                $outcome = 'exception:'.$exception->getMessage();
            }

            file_put_contents($dir.'/'.$index, $outcome);
            DB::disconnect();
            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    usleep(100_000);
    touch($barrier);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();

    $outcomes = [];

    foreach (array_keys($payloads) as $index) {
        $outcomes[] = (string) @file_get_contents($dir.'/'.$index);
        @unlink($dir.'/'.$index);
    }

    @unlink($barrier);
    @rmdir($dir);

    return $outcomes;
}

it('inserts readings exactly once under concurrent exact retries', function (): void {
    $payload = $this->fixture->batch($this->fixture->records(300, $this->start));

    $outcomes = ingestConcurrently($this->fixture, array_fill(0, 5, $payload));

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['inserted' => 1, 'replayed' => 4])
        ->and(DB::table('measurements')->count())->toBe(300)
        ->and(DB::table('measurement_batches')->count())->toBe(1);
});

it('returns 409 to concurrent changed payloads under the same batch id', function (): void {
    $records = $this->fixture->records(100, $this->start);
    $original = $this->fixture->batch($records);
    $changed = $original;
    $changed['records'][50]['laeq_db'] = 77.7;

    $outcomes = ingestConcurrently($this->fixture, [$original, $changed, $original, $changed]);
    $counts = array_count_values($outcomes);

    expect($counts['inserted'])->toBe(1)
        ->and(($counts['replayed'] ?? 0) + ($counts['batch_conflict'] ?? 0))->toBe(3)
        ->and($counts['batch_conflict'] ?? 0)->toBeGreaterThanOrEqual(2)
        ->and(DB::table('measurements')->count())->toBe(100);
});

it('deduplicates overlapping batches with different ids under concurrency', function (): void {
    $records = $this->fixture->records(200, $this->start);

    $outcomes = ingestConcurrently($this->fixture, [
        $this->fixture->batch(array_slice($records, 0, 150)),
        $this->fixture->batch(array_slice($records, 50, 150)),
        $this->fixture->batch(array_slice($records, 25, 150)),
    ]);

    $batches = DB::table('measurement_batches')->get();

    expect($outcomes)->each->toBe('inserted')
        ->and(DB::table('measurements')->count())->toBe(200)
        ->and($batches->sum('inserted_count'))->toBe(200)
        ->and($batches->sum('inserted_count') + $batches->sum('duplicate_count'))->toBe(450);
});

it('rejects the whole batch when a concurrent overlapping batch changed a row', function (): void {
    $records = $this->fixture->records(120, $this->start);
    $changed = array_slice($records, 60, 60);
    $changed[0]['lafmax_db'] = 99.0;

    $outcomes = ingestConcurrently($this->fixture, [
        $this->fixture->batch(array_slice($records, 0, 90)),
        $this->fixture->batch($changed),
    ]);

    sort($outcomes);
    $total = DB::table('measurements')->count();

    // Whichever commits first wins; the loser is rejected entirely with 409.
    expect($outcomes)->toBe(['inserted', 'measurement_conflict'])
        ->and($total)->toBeIn([90, 60]);
});

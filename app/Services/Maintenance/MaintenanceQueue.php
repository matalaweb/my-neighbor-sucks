<?php

namespace App\Services\Maintenance;

use App\Enums\MaintenanceJobKind;
use App\Enums\MaintenanceJobStatus;
use App\Models\MaintenanceJob;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Durable dirty-bucket / outbox records (spec §5, §11, §17).
 *
 * Marking is an upsert that bumps a generation counter. A worker claims a
 * record, processes it, and deletes it only if the generation is unchanged;
 * anything re-marked while it was running stays pending and is processed
 * again. A running record is never claimed by a second worker, so two
 * rebuilds of the same bucket cannot overlap and race.
 */
class MaintenanceQueue
{
    public const LOCK_SECONDS = 300;

    /**
     * Mark many work items dirty in one statement. Must be called inside the
     * same transaction as the data change it describes.
     *
     * @param  list<array{kind: MaintenanceJobKind, key: string, account_id?: int|null, subject_type?: string|null, subject_id?: int|null, bucket_start?: string|null, payload?: array<string, mixed>|null}>  $items
     */
    public function markMany(array $items, ?CarbonImmutable $availableAt = null): void
    {
        if ($items === []) {
            return;
        }

        $now = CarbonImmutable::now();
        $available = ($availableAt ?? $now)->format('Y-m-d H:i:s.u');
        $timestamp = $now->format('Y-m-d H:i:s.u');

        $rows = [];

        foreach ($items as $item) {
            $dedupe = hash('sha256', $item['kind']->value.'|'.$item['key']);
            $rows[$dedupe] = [
                $item['account_id'] ?? null,
                $item['kind']->value,
                $dedupe,
                $item['subject_type'] ?? null,
                $item['subject_id'] ?? null,
                $item['bucket_start'] ?? null,
                isset($item['payload']) ? json_encode($item['payload'], JSON_THROW_ON_ERROR) : null,
                1,
                MaintenanceJobStatus::Pending->value,
                0,
                $available,
                $timestamp,
                $timestamp,
            ];
        }

        // Sorted keys keep lock acquisition order stable across concurrent writers.
        ksort($rows);

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?)'));

            DB::statement(
                'INSERT INTO maintenance_jobs (account_id, kind, dedupe_key, subject_type, subject_id, bucket_start, payload, generation, status, attempts, available_at, created_at, updated_at) '
                .'VALUES '.$placeholders.' AS new '
                .'ON DUPLICATE KEY UPDATE generation = maintenance_jobs.generation + 1, '
                .'available_at = IF(maintenance_jobs.status = \'running\', maintenance_jobs.available_at, LEAST(maintenance_jobs.available_at, new.available_at)), '
                .'status = IF(maintenance_jobs.status = \'failed\', \'pending\', maintenance_jobs.status), '
                .'payload = COALESCE(new.payload, maintenance_jobs.payload), '
                .'updated_at = new.updated_at',
                array_merge(...$chunk),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function mark(MaintenanceJobKind $kind, string $key, ?int $accountId = null, ?string $subjectType = null, ?int $subjectId = null, ?array $payload = null, ?CarbonImmutable $availableAt = null): void
    {
        $this->markMany([[
            'kind' => $kind,
            'key' => $key,
            'account_id' => $accountId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
        ]], $availableAt);
    }

    /**
     * Claim up to $limit due records of the given kinds.
     *
     * @param  list<MaintenanceJobKind>  $kinds
     * @return list<array{id: int, generation: int, job: MaintenanceJob}>
     */
    public function claim(array $kinds, int $limit = 100): array
    {
        return DB::transaction(function () use ($kinds, $limit): array {
            $now = CarbonImmutable::now();

            $jobs = MaintenanceJob::query()
                ->whereIn('kind', array_map(fn (MaintenanceJobKind $kind): string => $kind->value, $kinds))
                ->where(function ($query) use ($now): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->where('status', MaintenanceJobStatus::Pending->value)->where('available_at', '<=', $now->format('Y-m-d H:i:s.u'));
                    })->orWhere(function ($expired) use ($now): void {
                        $expired->where('status', MaintenanceJobStatus::Running->value)->where('locked_until', '<', $now->format('Y-m-d H:i:s.u'));
                    });
                })
                ->orderBy('available_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            if ($jobs->isEmpty()) {
                return [];
            }

            MaintenanceJob::query()->whereKey($jobs->modelKeys())->update([
                'status' => MaintenanceJobStatus::Running->value,
                'locked_until' => $now->addSeconds(self::LOCK_SECONDS),
                'attempts' => DB::raw('attempts + 1'),
            ]);

            return $jobs->map(fn (MaintenanceJob $job): array => [
                'id' => $job->id,
                'generation' => $job->generation,
                'job' => $job,
            ])->all();
        });
    }

    /**
     * Finish a claimed record. Deleted only when no newer mark arrived while
     * processing; otherwise it is released for another pass.
     */
    public function complete(int $id, int $generation): void
    {
        $deleted = DB::table('maintenance_jobs')->where('id', $id)->where('generation', $generation)->delete();

        if ($deleted === 0) {
            DB::table('maintenance_jobs')->where('id', $id)->update([
                'status' => MaintenanceJobStatus::Pending->value,
                'locked_until' => null,
                'updated_at' => CarbonImmutable::now(),
            ]);
        }
    }

    public function release(int $id, int $delaySeconds): void
    {
        DB::table('maintenance_jobs')->where('id', $id)->update([
            'status' => MaintenanceJobStatus::Pending->value,
            'locked_until' => null,
            'available_at' => CarbonImmutable::now()->addSeconds($delaySeconds),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function fail(int $id, Throwable|string $error, int $maxAttempts = 10): void
    {
        $job = MaintenanceJob::query()->find($id);

        if ($job === null) {
            return;
        }

        $message = $error instanceof Throwable ? $error::class.': '.$error->getMessage() : $error;
        $exhausted = $job->attempts >= $maxAttempts;
        $backoff = min(3600, 2 ** min($job->attempts, 11));

        $job->forceFill([
            'status' => $exhausted ? MaintenanceJobStatus::Failed : MaintenanceJobStatus::Pending,
            'locked_until' => null,
            'available_at' => CarbonImmutable::now()->addSeconds($backoff),
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();
    }

    /**
     * Claim and process due records with a handler; returns processed count.
     *
     * @param  list<MaintenanceJobKind>  $kinds
     * @param  Closure(MaintenanceJob): void  $handler
     */
    public function work(array $kinds, Closure $handler, int $limit = 100, int $maxAttempts = 10): int
    {
        $processed = 0;

        foreach ($this->claim($kinds, $limit) as $claimed) {
            try {
                $handler($claimed['job']);
                $this->complete($claimed['id'], $claimed['generation']);
                $processed++;
            } catch (RetryLater $retry) {
                $this->release($claimed['id'], $retry->delaySeconds);
            } catch (Throwable $exception) {
                report($exception);
                $this->fail($claimed['id'], $exception, $maxAttempts);
            }
        }

        return $processed;
    }

    /**
     * @param  list<MaintenanceJobKind>  $kinds
     */
    public function hasDue(array $kinds): bool
    {
        return MaintenanceJob::query()
            ->whereIn('kind', array_map(fn (MaintenanceJobKind $kind): string => $kind->value, $kinds))
            ->where('status', MaintenanceJobStatus::Pending->value)
            ->where('available_at', '<=', CarbonImmutable::now()->format('Y-m-d H:i:s.u'))
            ->exists();
    }
}

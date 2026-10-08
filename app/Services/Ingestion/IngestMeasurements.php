<?php

namespace App\Services\Ingestion;

use App\Enums\MaintenanceJobKind;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Jobs\ProcessRollups;
use App\Models\Device;
use App\Models\MeasurementBatch;
use App\Services\Maintenance\MaintenanceQueue;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Atomically ingests one measurement batch (spec §8).
 *
 * The batch receipt, new rows, and dirty-bucket/outbox markers commit in one
 * transaction; success is returned only after that commit. Idempotency is
 * enforced by database uniqueness (device+batch UUID and
 * device/channel/boot/sequence) followed by a locking read that compares
 * row hashes, never by check-then-insert.
 */
class IngestMeasurements
{
    public function __construct(
        private readonly MeasurementBatchParser $parser,
        private readonly ProvenanceResolver $provenance,
        private readonly MaintenanceQueue $maintenance,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Device $device, array $payload, CarbonImmutable $receivedAt, ?string $requestId = null): IngestResult
    {
        $batch = $this->parser->parse(
            $payload,
            $receivedAt,
            fn (CarbonImmutable $capturedAt): bool => $device->importWindowCovers($capturedAt, $receivedAt),
        );

        $payloadHash = $batch->payloadHash();

        // Fast path for retries of an already-committed batch.
        $existing = $this->existingBatch($device, $batch->batchUuid);

        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        $streamIds = $this->provenance->resolveMeasurementStreams($device, $batch->records);

        try {
            $result = DB::transaction(
                fn (): IngestResult => $this->persist($device, $batch, $payloadHash, $streamIds, $receivedAt, $requestId),
                attempts: 3,
            );
        } catch (BatchAlreadyReceived) {
            // A concurrent request committed the same batch UUID first.
            return $this->replay($this->existingBatch($device, $batch->batchUuid), $payloadHash);
        }

        ProcessRollups::dispatchFor($device->id);

        return $result;
    }

    /**
     * @param  array<int, int>  $streamIds
     */
    private function persist(Device $device, MeasurementBatchData $batch, string $payloadHash, array $streamIds, CarbonImmutable $receivedAt, ?string $requestId): IngestResult
    {
        $records = $batch->records;
        usort($records, fn (MeasurementRecord $a, MeasurementRecord $b): int => [$a->channel, $a->bootId, $a->sequence] <=> [$b->channel, $b->bootId, $b->sequence]);

        // Identical repeats inside one batch count as duplicates; changed repeats conflict.
        $unique = [];
        $inBatchDuplicates = 0;

        foreach ($records as $record) {
            $key = $record->identityKey();

            if (isset($unique[$key])) {
                if ($unique[$key]->rowHash !== $record->rowHash) {
                    throw new DeviceApiException(ErrorCode::MeasurementConflict, 'The batch contains the same sequence identity twice with different values.', [
                        'conflicts' => [['channel' => $record->channel, 'boot_id' => $record->bootId, 'sequence' => $record->sequence, 'reason' => 'duplicate_in_batch']],
                    ]);
                }

                $inBatchDuplicates++;

                continue;
            }

            $unique[$key] = $record;
        }

        $receivedAtDb = Rfc3339::toDatabase($receivedAt);

        // Serialize ingestion per device. A single agent sends batches one at a
        // time, so this costs nothing in practice, and it prevents gap-lock
        // deadlocks between overlapping batches (and the later device-row
        // update) from turning a valid retry into a 5xx.
        DB::table('devices')->where('id', $device->id)->lockForUpdate()->first(['id']);

        try {
            $batchId = DB::table('measurement_batches')->insertGetId([
                'uuid' => $batch->batchUuid,
                'account_id' => $device->account_id,
                'device_id' => $device->id,
                'schema_version' => $batch->schemaVersion,
                'payload_hash' => $payloadHash,
                'sent_at' => $batch->sentAt ? Rfc3339::toDatabase($batch->sentAt) : null,
                'record_count' => count($batch->records),
                'received_at' => $receivedAtDb,
                'request_id' => $requestId,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BatchAlreadyReceived;
        }

        $this->insertRows($device, array_values($unique), $streamIds, $batchId, $receivedAtDb);

        [$inserted, $duplicates, $conflicts] = $this->classify($device, $unique, $batchId);

        if ($conflicts !== []) {
            throw new DeviceApiException(ErrorCode::MeasurementConflict, 'One or more sequence identities were already stored with different values; the whole batch was rejected.', [
                'conflicts' => array_slice($conflicts, 0, 50),
                'conflict_count' => count($conflicts),
            ]);
        }

        $duplicates += $inBatchDuplicates;
        $acceptedFrom = min(array_map(fn (MeasurementRecord $record): CarbonImmutable => $record->capturedAt, $batch->records));
        $acceptedTo = max(array_map(fn (MeasurementRecord $record): CarbonImmutable => $record->endsAt(), $batch->records));

        $this->markDerivedWork($device, $inserted, $streamIds);
        $this->touchDevice($device, $inserted, $streamIds, $receivedAtDb);

        $result = [
            'batch_id' => $batch->batchUuid,
            'status' => 'accepted',
            'record_count' => count($batch->records),
            'inserted_count' => count($inserted),
            'duplicate_count' => $duplicates,
            'accepted_interval' => [
                'start' => Rfc3339::format($acceptedFrom),
                'end' => Rfc3339::format($acceptedTo),
            ],
            'received_at' => Rfc3339::format($receivedAt),
        ];

        DB::table('measurement_batches')->where('id', $batchId)->update([
            'inserted_count' => count($inserted),
            'duplicate_count' => $duplicates,
            'accepted_from' => Rfc3339::toDatabase($acceptedFrom),
            'accepted_to' => Rfc3339::toDatabase($acceptedTo),
            'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        return new IngestResult($result, replayed: false);
    }

    /**
     * Bulk insert; an existing identity is left untouched (no-op update) and
     * then compared by hash in classify(), so conflicts are never hidden.
     *
     * @param  list<MeasurementRecord>  $records
     * @param  array<int, int>  $streamIds
     */
    private function insertRows(Device $device, array $records, array $streamIds, int $batchId, string $receivedAt): void
    {
        $columns = [
            'account_id', 'device_id', 'channel', 'boot_id', 'sequence', 'stream_id', 'configuration_revision',
            'captured_at', 'duration_ms', 'laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db',
            'rms_dbfs', 'quality_flags', 'null_reasons', 'bands', 'row_hash', 'batch_id', 'received_at',
        ];

        foreach (array_chunk($records, 300) as $chunk) {
            $bindings = [];

            foreach ($chunk as $record) {
                array_push(
                    $bindings,
                    $device->account_id,
                    $device->id,
                    $record->channel,
                    $record->bootId,
                    $record->sequence,
                    $streamIds[$record->index],
                    $record->configurationRevision,
                    Rfc3339::toDatabase($record->capturedAt),
                    $record->durationMs,
                    $record->metrics['laeq_db'],
                    $record->metrics['lafmax_db'],
                    $record->metrics['lceq_db'],
                    $record->metrics['lcpeak_db'],
                    $record->metrics['low_frequency_leq_db'],
                    $record->metrics['rms_dbfs'],
                    $record->qualityMask,
                    $record->nullReasons === [] ? null : json_encode($record->nullReasons, JSON_THROW_ON_ERROR),
                    $record->bands === [] ? null : json_encode($record->bands, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    $record->rowHash,
                    $batchId,
                    $receivedAt,
                );
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '('.implode(',', array_fill(0, count($columns), '?')).')'));

            DB::statement(
                'INSERT INTO measurements ('.implode(',', $columns).') VALUES '.$placeholders.' ON DUPLICATE KEY UPDATE id = id',
                $bindings,
            );
        }
    }

    /**
     * Locking read of every identity in the batch. Rows owned by this batch
     * were inserted; rows owned by another batch are duplicates when the hash
     * matches and conflicts otherwise. Replay receipts for purged rows are
     * consulted so deleted readings cannot be resurrected.
     *
     * @param  array<string, MeasurementRecord>  $unique
     * @return array{0: list<array{record: MeasurementRecord, id: int}>, 1: int, 2: list<array<string, mixed>>}
     */
    private function classify(Device $device, array $unique, int $batchId): array
    {
        $groups = [];

        foreach ($unique as $record) {
            $groups[$record->channel."\0".$record->bootId][] = $record->sequence;
        }

        $stored = [];
        $receipts = [];

        foreach ($groups as $group => $sequences) {
            [$channel, $bootId] = explode("\0", $group);

            $rows = DB::table('measurements')
                ->select(['id', 'sequence', 'row_hash', 'batch_id'])
                ->where('device_id', $device->id)
                ->where('channel', $channel)
                ->where('boot_id', $bootId)
                ->whereBetween('sequence', [min($sequences), max($sequences)])
                ->sharedLock()
                ->get();

            foreach ($rows as $row) {
                $stored[$channel.'|'.$bootId.'|'.$row->sequence] = $row;
            }

            $receiptRows = DB::table('measurement_receipts')
                ->select(['sequence', 'row_hash'])
                ->where('device_id', $device->id)
                ->where('channel', $channel)
                ->where('boot_id', $bootId)
                ->whereBetween('sequence', [min($sequences), max($sequences)])
                ->sharedLock()
                ->get();

            foreach ($receiptRows as $row) {
                $receipts[$channel.'|'.$bootId.'|'.$row->sequence] = $row->row_hash;
            }
        }

        $inserted = [];
        $duplicates = 0;
        $conflicts = [];
        $resurrected = [];

        foreach ($unique as $key => $record) {
            $row = $stored[$key] ?? null;

            if ($row === null) {
                throw new \RuntimeException('Measurement row missing after insert for '.$key);
            }

            $conflict = fn (string $reason): array => [
                'channel' => $record->channel,
                'boot_id' => $record->bootId,
                'sequence' => $record->sequence,
                'reason' => $reason,
            ];

            if ((int) $row->batch_id === $batchId) {
                if (isset($receipts[$key])) {
                    $resurrected[] = (int) $row->id;

                    if (! hash_equals($receipts[$key], $record->rowHash)) {
                        $conflicts[] = $conflict('changed_after_retention_purge');
                    } else {
                        $duplicates++;
                    }

                    continue;
                }

                $inserted[] = ['record' => $record, 'id' => (int) $row->id];

                continue;
            }

            if (hash_equals($row->row_hash, $record->rowHash)) {
                $duplicates++;
            } else {
                $conflicts[] = $conflict('changed_payload');
            }
        }

        if ($resurrected !== [] && $conflicts === []) {
            DB::table('measurements')->whereIn('id', $resurrected)->delete();
        }

        return [$inserted, $duplicates, $conflicts];
    }

    /**
     * @param  list<array{record: MeasurementRecord, id: int}>  $inserted
     * @param  array<int, int>  $streamIds
     */
    private function markDerivedWork(Device $device, array $inserted, array $streamIds): void
    {
        if ($inserted === []) {
            return;
        }

        $items = [];
        $ranges = [];

        foreach ($inserted as ['record' => $record]) {
            $minute = $record->capturedAt->startOfMinute()->format('Y-m-d H:i:s');
            $items[$record->channel.'|'.$minute] = [
                'kind' => MaintenanceJobKind::RollupMinute,
                'key' => $device->id.'|'.$record->channel.'|'.$minute,
                'account_id' => $device->account_id,
                'subject_type' => 'device',
                'subject_id' => $device->id,
                'bucket_start' => $minute,
                'payload' => ['channel' => $record->channel],
            ];

            $range = $ranges[$record->channel] ?? [$record->capturedAt, $record->endsAt()];
            $ranges[$record->channel] = [
                $record->capturedAt->lessThan($range[0]) ? $record->capturedAt : $range[0],
                $record->endsAt()->greaterThan($range[1]) ? $record->endsAt() : $range[1],
            ];
        }

        // Events whose snapshot window overlaps newly arrived readings are
        // supplemented (late data) by the snapshot worker.
        $pre = (int) config('noise.events.snapshot_pre_seconds');
        $post = (int) config('noise.events.snapshot_post_seconds');

        foreach ($ranges as $channel => [$from, $to]) {
            $eventIds = DB::table('noise_events')
                ->where('device_id', $device->id)
                ->where('channel', $channel)
                ->where('started_at', '<', Rfc3339::toDatabase($to->addSeconds($pre)))
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', Rfc3339::toDatabase($from->subSeconds($post))))
                ->pluck('id');

            foreach ($eventIds as $eventId) {
                $items['event|'.$eventId] = [
                    'kind' => MaintenanceJobKind::EventSnapshot,
                    'key' => (string) $eventId,
                    'account_id' => $device->account_id,
                    'subject_type' => 'noise_event',
                    'subject_id' => (int) $eventId,
                ];
            }
        }

        $this->maintenance->markMany(array_values($items));
    }

    /**
     * @param  list<array{record: MeasurementRecord, id: int}>  $inserted
     * @param  array<int, int>  $streamIds
     */
    private function touchDevice(Device $device, array $inserted, array $streamIds, string $receivedAt): void
    {
        $update = [
            'last_contact_at' => DB::raw("GREATEST(COALESCE(last_contact_at, '1970-01-01'), '{$receivedAt}')"),
        ];

        if ($inserted !== []) {
            $latest = max(array_map(fn (array $item): CarbonImmutable => $item['record']->capturedAt, $inserted));
            $latestDb = Rfc3339::toDatabase($latest);
            $update['latest_capture_at'] = DB::raw("GREATEST(COALESCE(latest_capture_at, '1970-01-01'), '{$latestDb}')");
            $update['latest_measurement_received_at'] = $receivedAt;

            $byStream = [];

            foreach ($inserted as ['record' => $record]) {
                $streamId = $streamIds[$record->index];
                $byStream[$streamId][0] = isset($byStream[$streamId][0]) && $byStream[$streamId][0]->lessThan($record->capturedAt) ? $byStream[$streamId][0] : $record->capturedAt;
                $byStream[$streamId][1] = isset($byStream[$streamId][1]) && $byStream[$streamId][1]->greaterThan($record->capturedAt) ? $byStream[$streamId][1] : $record->capturedAt;
            }

            foreach ($byStream as $streamId => [$first, $last]) {
                $firstDb = Rfc3339::toDatabase($first);
                $lastDb = Rfc3339::toDatabase($last);

                DB::table('measurement_streams')->where('id', $streamId)->update([
                    'first_captured_at' => DB::raw("LEAST(COALESCE(first_captured_at, '{$firstDb}'), '{$firstDb}')"),
                    'last_captured_at' => DB::raw("GREATEST(COALESCE(last_captured_at, '{$lastDb}'), '{$lastDb}')"),
                ]);
            }
        }

        DB::table('devices')->where('id', $device->id)->update($update);
    }

    private function existingBatch(Device $device, string $batchUuid): ?MeasurementBatch
    {
        return MeasurementBatch::query()->where('device_id', $device->id)->where('uuid', $batchUuid)->first();
    }

    private function replay(?MeasurementBatch $existing, string $payloadHash): IngestResult
    {
        if ($existing === null || $existing->result === null) {
            throw new DeviceApiException(ErrorCode::ServiceUnavailable, 'Batch is being processed by a concurrent request; retry shortly.', [], ['Retry-After' => '2']);
        }

        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw new DeviceApiException(ErrorCode::BatchConflict, 'This batch_id was already received with a different payload.', [
                'batch_id' => $existing->uuid,
                'original_received_at' => Rfc3339::format($existing->received_at),
            ]);
        }

        return new IngestResult([
            ...$existing->result,
            'original_request_id' => $existing->request_id,
        ], replayed: true);
    }
}

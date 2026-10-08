<?php

namespace App\Services\Ingestion;

use App\Enums\CalibrationState;
use App\Enums\Metric;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Models\Device;
use App\Models\DeviceCalibration;
use App\Models\DeviceDeployment;
use App\Models\MeasurementProfile;
use App\Models\MeasurementStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validates that every provenance reference belongs to the authenticated
 * device and is consistent with the record (spec §4, §5, §8), and resolves
 * stream identities. Profiles, calibrations, and deployments must already be
 * provisioned by an owner.
 */
class ProvenanceResolver
{
    /** @var array<string, DeviceDeployment> */
    private array $deployments = [];

    /** @var array<string, MeasurementProfile> */
    private array $profiles = [];

    /** @var array<string, DeviceCalibration> */
    private array $calibrations = [];

    /** @var array<int, true> */
    private array $configurationRevisions = [];

    /**
     * Load references for the given UUIDs/revisions (one query per kind).
     *
     * @param  list<string>  $deploymentUuids
     * @param  list<string>  $profileUuids
     * @param  list<string>  $calibrationUuids
     * @param  list<int>  $revisions
     */
    public function load(Device $device, array $deploymentUuids, array $profileUuids, array $calibrationUuids, array $revisions): self
    {
        $this->deployments = $deploymentUuids === [] ? [] : DeviceDeployment::query()
            ->where('device_id', $device->id)->whereIn('uuid', array_unique($deploymentUuids))
            ->get()->keyBy('uuid')->all();

        $this->profiles = $profileUuids === [] ? [] : MeasurementProfile::query()
            ->where('device_id', $device->id)->whereIn('uuid', array_unique($profileUuids))
            ->get()->keyBy('uuid')->all();

        $this->calibrations = $calibrationUuids === [] ? [] : DeviceCalibration::query()
            ->where('device_id', $device->id)->whereIn('uuid', array_unique($calibrationUuids))
            ->get()->keyBy('uuid')->all();

        $this->configurationRevisions = $revisions === [] ? [] : array_fill_keys(
            DB::table('device_configurations')->where('device_id', $device->id)->whereIn('revision', array_unique($revisions))->pluck('revision')->map(fn ($revision): int => (int) $revision)->all(),
            true,
        );

        return $this;
    }

    /**
     * @param  list<MeasurementRecord>  $records
     * @return array<int, int> record index => stream id
     */
    public function resolveMeasurementStreams(Device $device, array $records): array
    {
        $this->load(
            $device,
            array_map(fn (MeasurementRecord $record): string => $record->deploymentUuid, $records),
            array_map(fn (MeasurementRecord $record): string => $record->profileUuid, $records),
            array_values(array_filter(array_map(fn (MeasurementRecord $record): ?string => $record->calibrationUuid, $records))),
            array_map(fn (MeasurementRecord $record): int => $record->configurationRevision, $records),
        );

        $errors = [];
        $unknown = false;
        $streamTuples = [];

        foreach ($records as $record) {
            $path = 'records.'.$record->index;
            $tuple = $this->check(
                $path,
                $record->channel,
                $record->capturedAt,
                $record->deploymentUuid,
                $record->profileUuid,
                $record->calibrationUuid,
                $record->configurationRevision,
                $errors,
                $unknown,
            );

            if ($tuple === null) {
                continue;
            }

            [$deployment, $profile, $calibration] = $tuple;

            foreach (Metric::cases() as $metric) {
                $value = $record->metrics[$metric->value];
                $applicable = $profile->supports($metric) && (! $metric->isAbsolute() || $profile->calibration_state->allowsAbsoluteLevels());

                if ($value !== null && ! $applicable) {
                    $errors[$path.'.'.$metric->value][] = $metric->isAbsolute() && ! $profile->calibration_state->allowsAbsoluteLevels()
                        ? 'Absolute SPL metrics must be null for an uncalibrated profile.'
                        : 'Metric is not supported by the referenced measurement profile.';
                }

                if ($value === null && $applicable && ! isset($record->nullReasons[$metric->value]) && $record->qualityFlags === []) {
                    $errors[$path.'.null_reasons.'.$metric->value][] = 'A supported metric that is null needs a reason (or a quality flag).';
                }
            }

            $supportedBands = $profile->supportedBandCenters();

            foreach ($record->bands as $bandIndex => $band) {
                if (! in_array((string) $band['center_hz'], $supportedBands, true)) {
                    $errors[$path.'.bands.'.$bandIndex.'.center_hz'][] = 'Band is not defined by the referenced measurement profile.';
                }
            }

            $streamTuples[$record->index] = [$record->channel, $deployment->id, $profile->id, $calibration?->id, $profile->calibration_state];
        }

        if ($errors !== []) {
            throw new DeviceApiException(
                $unknown ? ErrorCode::UnknownProvenance : ErrorCode::ValidationFailed,
                $unknown
                    ? 'One or more provenance references are not provisioned for this device; refresh configuration.'
                    : 'One or more records are inconsistent with their provenance; nothing was stored.',
                ['errors' => $errors],
            );
        }

        return $this->streamIds($device, $streamTuples);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{0: DeviceDeployment, 1: MeasurementProfile, 2: DeviceCalibration|null}|null
     */
    public function check(
        string $path,
        string $channel,
        CarbonImmutable $capturedAt,
        string $deploymentUuid,
        string $profileUuid,
        ?string $calibrationUuid,
        int $configurationRevision,
        array &$errors,
        bool &$unknown,
    ): ?array {
        $deployment = $this->deployments[$deploymentUuid] ?? null;
        $profile = $this->profiles[$profileUuid] ?? null;
        $calibration = $calibrationUuid === null ? null : ($this->calibrations[$calibrationUuid] ?? null);
        $before = count($errors);

        if ($deployment === null) {
            $errors[$path.'.deployment_id'][] = 'Unknown deployment for this device.';
            $unknown = true;
        } elseif ($capturedAt->lessThan($deployment->effective_at)) {
            $errors[$path.'.deployment_id'][] = 'Capture time precedes the deployment effective time.';
        }

        if ($profile === null) {
            $errors[$path.'.profile_id'][] = 'Unknown measurement profile for this device.';
            $unknown = true;
        } elseif ($profile->channel !== $channel) {
            $errors[$path.'.profile_id'][] = 'Profile channel does not match record channel.';
        }

        if ($calibrationUuid !== null && $calibration === null) {
            $errors[$path.'.calibration_id'][] = 'Unknown calibration for this device.';
            $unknown = true;
        }

        if (! isset($this->configurationRevisions[$configurationRevision])) {
            $errors[$path.'.configuration_revision'][] = 'Unknown configuration revision for this device.';
            $unknown = true;
        }

        if ($profile !== null) {
            $state = $profile->calibration_state;

            if ($state === CalibrationState::Uncalibrated && $calibrationUuid !== null) {
                $errors[$path.'.calibration_id'][] = 'An uncalibrated profile must not reference a calibration.';
            }

            if ($state !== CalibrationState::Uncalibrated && $calibrationUuid === null) {
                $errors[$path.'.calibration_id'][] = 'An '.$state->value.' profile requires a calibration reference.';
            }

            if ($calibration !== null) {
                if ($calibration->channel !== $channel) {
                    $errors[$path.'.calibration_id'][] = 'Calibration channel does not match record channel.';
                }

                if ($calibration->calibration_state !== $state) {
                    $errors[$path.'.calibration_id'][] = 'Calibration state ('.$calibration->calibration_state->value.') does not match profile state ('.$state->value.'); estimated and calibrated data are never combined.';
                }
            }
        }

        if (count($errors) > $before || $deployment === null || $profile === null) {
            return null;
        }

        return [$deployment, $profile, $calibration];
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int, 3: int|null, 4: CalibrationState}>  $tuples
     * @return array<int, int>
     */
    public function streamIds(Device $device, array $tuples): array
    {
        $keys = [];

        foreach ($tuples as $index => [$channel, $deploymentId, $profileId, $calibrationId, $state]) {
            $key = MeasurementStream::keyFor($device->id, $channel, $deploymentId, $profileId, $calibrationId);
            $keys[$key] = [$channel, $deploymentId, $profileId, $calibrationId, $state];
        }

        $existing = DB::table('measurement_streams')->whereIn('stream_key', array_keys($keys))->pluck('id', 'stream_key')->all();
        $missing = array_diff_key($keys, $existing);

        if ($missing !== []) {
            $now = CarbonImmutable::now();

            // Streams carry no payload beyond their identity key, so ignoring a
            // concurrent duplicate cannot hide a conflicting value.
            DB::table('measurement_streams')->insertOrIgnore(array_map(fn (array $tuple, string $key): array => [
                'account_id' => $device->account_id,
                'device_id' => $device->id,
                'channel' => $tuple[0],
                'device_deployment_id' => $tuple[1],
                'measurement_profile_id' => $tuple[2],
                'device_calibration_id' => $tuple[3],
                'calibration_state' => $tuple[4]->value,
                'stream_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing, array_keys($missing)));

            $existing = DB::table('measurement_streams')->whereIn('stream_key', array_keys($keys))->pluck('id', 'stream_key')->all();
        }

        $result = [];

        foreach ($tuples as $index => [$channel, $deploymentId, $profileId, $calibrationId]) {
            $result[$index] = (int) $existing[MeasurementStream::keyFor($device->id, $channel, $deploymentId, $profileId, $calibrationId)];
        }

        return $result;
    }
}

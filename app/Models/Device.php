<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToAccount, HasFactory, HasPublicUuid;

    protected $fillable = [
        'account_id', 'property_id', 'name', 'status', 'capabilities', 'software_version',
        'reporting_interval_seconds', 'heartbeat_interval_seconds',
        'import_window_starts_at', 'import_window_expires_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'capabilities' => 'array',
            'last_contact_at' => 'immutable_datetime',
            'latest_capture_at' => 'immutable_datetime',
            'latest_measurement_received_at' => 'immutable_datetime',
            'import_window_starts_at' => 'immutable_datetime',
            'import_window_expires_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<DeviceCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
    }

    /** @return HasMany<DeviceDeployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(DeviceDeployment::class);
    }

    /** @return HasMany<MeasurementProfile, $this> */
    public function measurementProfiles(): HasMany
    {
        return $this->hasMany(MeasurementProfile::class);
    }

    /** @return HasMany<DeviceCalibration, $this> */
    public function calibrations(): HasMany
    {
        return $this->hasMany(DeviceCalibration::class);
    }

    /** @return HasMany<DeviceConfiguration, $this> */
    public function configurations(): HasMany
    {
        return $this->hasMany(DeviceConfiguration::class);
    }

    /** @return HasMany<DeviceConfigAcknowledgment, $this> */
    public function configAcknowledgments(): HasMany
    {
        return $this->hasMany(DeviceConfigAcknowledgment::class);
    }

    /** @return HasMany<DeviceHeartbeat, $this> */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(DeviceHeartbeat::class);
    }

    /** @return BelongsTo<DeviceHeartbeat, $this> */
    public function latestHeartbeat(): BelongsTo
    {
        return $this->belongsTo(DeviceHeartbeat::class, 'latest_heartbeat_id');
    }

    /** @return HasMany<MeasurementStream, $this> */
    public function streams(): HasMany
    {
        return $this->hasMany(MeasurementStream::class);
    }

    /** @return HasMany<NoiseEvent, $this> */
    public function noiseEvents(): HasMany
    {
        return $this->hasMany(NoiseEvent::class);
    }

    public function isArchived(): bool
    {
        return $this->status === DeviceStatus::Archived;
    }

    public function latestConfiguration(): ?DeviceConfiguration
    {
        return $this->configurations()->orderByDesc('revision')->first();
    }

    /**
     * Readings are stale when capture age exceeds two expected batch periods plus 30 seconds.
     */
    public function staleAfterSeconds(): int
    {
        return 2 * $this->reporting_interval_seconds + (int) config('noise.dashboard.stale_grace_seconds');
    }

    /**
     * Offline after three missed heartbeat intervals.
     */
    public function offlineAfterSeconds(): int
    {
        return (int) config('noise.dashboard.offline_missed_heartbeats') * $this->heartbeat_interval_seconds;
    }

    public function isOnline(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->last_contact_at !== null
            && $this->last_contact_at->diffInSeconds($now) <= $this->offlineAfterSeconds();
    }

    public function measurementsAreStale(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->latest_capture_at === null
            || $this->latest_capture_at->diffInSeconds($now) > $this->staleAfterSeconds();
    }

    public function importWindowCovers(CarbonImmutable $capturedAt, CarbonImmutable $now): bool
    {
        return $this->import_window_starts_at !== null
            && $this->import_window_expires_at !== null
            && $this->import_window_expires_at->greaterThan($now)
            && $capturedAt->greaterThanOrEqualTo($this->import_window_starts_at);
    }
}

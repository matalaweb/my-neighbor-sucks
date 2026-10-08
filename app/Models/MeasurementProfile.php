<?php

namespace App\Models;

use App\Enums\CalibrationState;
use App\Enums\Metric;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable device/channel processing and gain settings.
 */
class MeasurementProfile extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id', 'device_id', 'channel', 'revision', 'name', 'microphone_model', 'microphone_serial',
        'audio_interface', 'sample_rate_hz', 'gain_db', 'gain_description', 'weighting_implementation_version',
        'filter_implementation_version', 'calibration_state', 'calibration_application_method',
        'supported_metrics', 'low_frequency_lower_hz', 'low_frequency_upper_hz', 'band_definitions',
        'agent_processing_version', 'content_hash', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'calibration_state' => CalibrationState::class,
            'supported_metrics' => 'array',
            'band_definitions' => 'array',
            'gain_db' => 'decimal:2',
            'low_frequency_lower_hz' => 'decimal:2',
            'low_frequency_upper_hz' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supports(Metric $metric): bool
    {
        return in_array($metric->value, $this->supported_metrics ?? [], true);
    }

    /**
     * Supported nominal third-octave centres (Hz) as strings, e.g. "31.5".
     *
     * @return list<string>
     */
    public function supportedBandCenters(): array
    {
        return array_values(array_map(
            fn (array $band): string => (string) $band['center_hz'],
            $this->band_definitions['bands'] ?? [],
        ));
    }

    public function lowFrequencyBandLabel(): ?string
    {
        if ($this->low_frequency_lower_hz === null || $this->low_frequency_upper_hz === null) {
            return null;
        }

        return rtrim(rtrim((string) $this->low_frequency_lower_hz, '0'), '.').'–'.rtrim(rtrim((string) $this->low_frequency_upper_hz, '0'), '.').' Hz';
    }

    public function label(): string
    {
        return 'Profile r'.$this->revision.' — '.$this->channel.' · '.$this->microphone_model.' · '.$this->calibration_state->getLabel();
    }
}

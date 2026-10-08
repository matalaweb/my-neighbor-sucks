<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Device-reported quality flags (spec §4), stored as a bitmask on
 * measurements. Bits are part of the persisted format: never renumber.
 */
enum QualityFlag: string implements HasLabel
{
    case Clipping = 'clipping';
    case AudioDropout = 'audio_dropout';
    case MicrophoneDisconnected = 'microphone_disconnected';
    case UnsynchronizedClock = 'unsynchronized_clock';
    case BelowNoiseFloor = 'below_noise_floor';
    case InvalidCalibration = 'invalid_calibration';
    case ProcessingError = 'processing_error';
    case IncompleteInterval = 'incomplete_interval';

    public function bit(): int
    {
        return match ($this) {
            self::Clipping => 1,
            self::AudioDropout => 2,
            self::MicrophoneDisconnected => 4,
            self::UnsynchronizedClock => 8,
            self::BelowNoiseFloor => 16,
            self::InvalidCalibration => 32,
            self::ProcessingError => 64,
            self::IncompleteInterval => 128,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Clipping => 'Clipping',
            self::AudioDropout => 'Audio dropout',
            self::MicrophoneDisconnected => 'Microphone disconnected',
            self::UnsynchronizedClock => 'Unsynchronized clock',
            self::BelowNoiseFloor => 'Below usable noise floor',
            self::InvalidCalibration => 'Invalid calibration',
            self::ProcessingError => 'Processing error',
            self::IncompleteInterval => 'Incomplete interval',
        };
    }

    /**
     * @param  iterable<self|string>  $flags
     */
    public static function toMask(iterable $flags): int
    {
        $mask = 0;

        foreach ($flags as $flag) {
            $mask |= ($flag instanceof self ? $flag : self::from($flag))->bit();
        }

        return $mask;
    }

    /**
     * @return list<self>
     */
    public static function fromMask(int $mask): array
    {
        return array_values(array_filter(self::cases(), fn (self $flag): bool => ($mask & $flag->bit()) !== 0));
    }

    /**
     * @return list<string>
     */
    public static function valuesFromMask(int $mask): array
    {
        return array_map(fn (self $flag): string => $flag->value, self::fromMask($mask));
    }
}

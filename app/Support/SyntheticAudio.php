<?php

namespace App\Support;

/**
 * SYNTHETIC test audio. Generates small mono 16-bit PCM WAV clips whose
 * envelopes loosely imitate a vehicle-like burst or a garage-door-like
 * rumble. These signals are clearly artificial: they exercise the upload
 * and verification pipeline and never validate source classification.
 */
final class SyntheticAudio
{
    /**
     * @param  'background'|'vehicle'|'garage'  $pattern
     */
    public static function wav(int $durationMs = 2000, int $sampleRate = 8000, string $pattern = 'vehicle', int $seed = 1): string
    {
        mt_srand($seed);
        $frames = intdiv($durationMs * $sampleRate, 1000);
        $data = '';

        for ($i = 0; $i < $frames; $i++) {
            $t = $i / $sampleRate;
            $progress = $frames > 1 ? $i / ($frames - 1) : 0.0;
            $noise = (mt_rand() / mt_getrandmax()) * 2 - 1;

            $value = match ($pattern) {
                'vehicle' => sin(M_PI * $progress) * (0.5 * sin(2 * M_PI * 90 * $t) + 0.3 * sin(2 * M_PI * 180 * $t) + 0.2 * $noise),
                'garage' => 0.35 * (0.6 * sin(2 * M_PI * 50 * $t) + 0.4 * $noise) * (0.7 + 0.3 * sin(2 * M_PI * 3 * $t)),
                default => 0.02 * $noise,
            };

            $data .= pack('v', ((int) round(max(-1.0, min(1.0, $value)) * 32767)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    /**
     * Declaration fields for a generated clip (recording_id etc. added by caller).
     *
     * @return array{mime_type: string, codec: string, sample_rate_hz: int, channel_count: int, bit_depth: int, byte_size: int, sha256: string, duration_ms: int}
     */
    public static function describe(string $bytes, int $durationMs, int $sampleRate = 8000): array
    {
        return [
            'mime_type' => 'audio/wav',
            'codec' => 'pcm_s16le',
            'sample_rate_hz' => $sampleRate,
            'channel_count' => 1,
            'bit_depth' => 16,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'duration_ms' => $durationMs,
        ];
    }
}

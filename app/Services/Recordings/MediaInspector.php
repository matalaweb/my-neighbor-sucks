<?php

namespace App\Services\Recordings;

/**
 * Reads container headers of preserved originals without transcoding.
 * Supports PCM/float WAV (RIFF/RF64 headers) and FLAC STREAMINFO.
 */
class MediaInspector
{
    /**
     * @return array{container: string, codec: string, sample_rate_hz: int, channel_count: int, bit_depth: int|null, duration_ms: int, frames: int}|null
     */
    public function inspect(string $path): ?array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $magic = fread($handle, 4);

            return match ($magic) {
                'RIFF' => $this->wav($handle),
                'fLaC' => $this->flac($handle),
                default => null,
            };
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array<string, int|string|null>|null
     */
    private function wav($handle): ?array
    {
        fread($handle, 4); // RIFF size

        if (fread($handle, 4) !== 'WAVE') {
            return null;
        }

        $format = null;
        $dataSize = null;

        while (! feof($handle)) {
            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                break;
            }

            ['id' => $id, 'size' => $size] = unpack('a4id/Vsize', $header);

            if ($id === 'fmt ') {
                $body = fread($handle, $size);

                if (strlen($body) < 16) {
                    return null;
                }

                $format = unpack('vaudio_format/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits', substr($body, 0, 16));

                if ($format['audio_format'] === 0xFFFE && strlen($body) >= 26) {
                    $format['audio_format'] = unpack('v', substr($body, 24, 2))[1];
                }

                if ($size % 2 === 1) {
                    fread($handle, 1);
                }

                continue;
            }

            if ($id === 'data') {
                $dataSize = $size;

                break;
            }

            fseek($handle, $size + ($size % 2), SEEK_CUR);
        }

        if ($format === null || $dataSize === null || $format['block_align'] === 0 || $format['sample_rate'] === 0) {
            return null;
        }

        $frames = intdiv($dataSize, $format['block_align']);
        $codec = match ((int) $format['audio_format']) {
            1 => 'pcm_s'.$format['bits'].'le',
            3 => 'pcm_f'.$format['bits'].'le',
            default => 'wav_format_'.$format['audio_format'],
        };

        if ($format['audio_format'] === 1 && $format['bits'] === 8) {
            $codec = 'pcm_u8';
        }

        return [
            'container' => 'wav',
            'codec' => $codec,
            'sample_rate_hz' => (int) $format['sample_rate'],
            'channel_count' => (int) $format['channels'],
            'bit_depth' => (int) $format['bits'],
            'frames' => $frames,
            'duration_ms' => (int) round($frames * 1000 / $format['sample_rate']),
        ];
    }

    /**
     * @param  resource  $handle
     * @return array<string, int|string|null>|null
     */
    private function flac($handle): ?array
    {
        $blockHeader = fread($handle, 4);

        if ($blockHeader === false || strlen($blockHeader) < 4 || (ord($blockHeader[0]) & 0x7F) !== 0) {
            return null;
        }

        $info = fread($handle, 34);

        if ($info === false || strlen($info) < 34) {
            return null;
        }

        $bytes = array_values(unpack('C*', substr($info, 10, 8)));
        $sampleRate = ($bytes[0] << 12) | ($bytes[1] << 4) | ($bytes[2] >> 4);
        $channels = (($bytes[2] >> 1) & 0x07) + 1;
        $bitsPerSample = ((($bytes[2] & 0x01) << 4) | ($bytes[3] >> 4)) + 1;
        $totalSamples = (($bytes[3] & 0x0F) << 32) | ($bytes[4] << 24) | ($bytes[5] << 16) | ($bytes[6] << 8) | $bytes[7];

        if ($sampleRate === 0) {
            return null;
        }

        return [
            'container' => 'flac',
            'codec' => 'flac',
            'sample_rate_hz' => $sampleRate,
            'channel_count' => $channels,
            'bit_depth' => $bitsPerSample,
            'frames' => $totalSamples,
            'duration_ms' => (int) round($totalSamples * 1000 / $sampleRate),
        ];
    }
}

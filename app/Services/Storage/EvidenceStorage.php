<?php

namespace App\Services\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Private object storage for recordings, exports, and attachments.
 *
 * Production (Laravel Cloud) attaches a private bucket as the default disk.
 * Locally, the same S3 API is reached at different hostnames by the app
 * container, devices, and browsers, so URL signing may use an alternate
 * endpoint. Signing is offline; no request is made to that endpoint.
 */
class EvidenceStorage
{
    private ?FilesystemAdapter $browserDisk = null;

    private ?FilesystemAdapter $deviceDisk = null;

    public function diskName(): string
    {
        return (string) config('noise.storage.disk');
    }

    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($this->diskName());
    }

    /**
     * Short-lived presigned PUT for a server-generated staging key.
     *
     * @return array{url: string, headers: array<string, string|list<string>>, method: string}
     */
    public function deviceUploadUrl(string $key, CarbonImmutable $expiresAt, string $contentType): array
    {
        $signed = $this->signingDisk('device')->temporaryUploadUrl($key, $expiresAt, [
            'ContentType' => $contentType,
        ]);

        return [
            'method' => 'PUT',
            'url' => $signed['url'],
            'headers' => $this->flattenHeaders($signed['headers']),
        ];
    }

    /**
     * Short-lived presigned GET for an authorized browser (supports HTTP Range).
     */
    public function browserUrl(string $key, CarbonImmutable $expiresAt, ?string $downloadName = null, ?string $contentType = null): string
    {
        $options = [];

        if ($downloadName !== null) {
            $options['ResponseContentDisposition'] = $this->contentDisposition($downloadName);
        }

        if ($contentType !== null) {
            $options['ResponseContentType'] = $contentType;
        }

        return $this->signingDisk('browser')->temporaryUrl($key, $expiresAt, $options);
    }

    public function contentDisposition(string $filename, string $type = 'attachment'): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $fallback, rawurlencode($filename));
    }

    private function signingDisk(string $audience): FilesystemAdapter
    {
        $endpoint = config('noise.storage.'.$audience.'_endpoint');

        if (blank($endpoint) || config('filesystems.disks.'.$this->diskName().'.driver') !== 's3') {
            return $this->disk();
        }

        $property = $audience === 'browser' ? 'browserDisk' : 'deviceDisk';

        return $this->{$property} ??= Storage::build(array_merge(
            config('filesystems.disks.'.$this->diskName()),
            ['endpoint' => $endpoint],
        ));
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'host') {
                continue;
            }

            $flat[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $flat;
    }

    /**
     * Stream an object to a local temporary file while hashing the same bytes.
     *
     * @return array{path: string, sha256: string, bytes: int}
     */
    public function downloadAndHash(string $key, ?Filesystem $disk = null): array
    {
        $disk ??= $this->disk();
        $source = $disk->readStream($key);
        $path = tempnam(sys_get_temp_dir(), 'nm-');
        $target = fopen($path, 'w+b');
        $context = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new \RuntimeException('Failed reading object stream.');
                }

                hash_update($context, $chunk);
                fwrite($target, $chunk);
                $bytes += strlen($chunk);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return ['path' => $path, 'sha256' => hash_final($context), 'bytes' => $bytes];
    }
}

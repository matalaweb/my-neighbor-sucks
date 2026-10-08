<?php

namespace App\Http\DeviceApi;

/**
 * Every device request body carries schema_version (spec §7).
 */
final class SchemaVersion
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assert(array $payload): void
    {
        $supported = (int) config('noise.device_api.schema_version');

        if (($payload['schema_version'] ?? null) !== $supported) {
            throw new DeviceApiException(ErrorCode::UnsupportedSchemaVersion, 'schema_version must be '.$supported.'.', [
                'supported_schema_versions' => [$supported],
            ]);
        }
    }
}

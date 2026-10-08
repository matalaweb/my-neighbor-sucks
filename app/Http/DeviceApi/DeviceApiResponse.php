<?php

namespace App\Http\DeviceApi;

use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Every device API response carries request_id and server_received_at.
 */
final class DeviceApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(Request $request, array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            array_merge(self::envelope($request), $data),
            $status,
            $headers,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(Request $request, ErrorCode $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        return self::make($request, [
            'error' => array_filter([
                'code' => $code->value,
                'message' => $message,
                'retry' => $code->retry(),
                'permanent' => $code->permanent(),
                'details' => $details === [] ? null : $details,
            ], fn ($value): bool => $value !== null),
        ], $code->status(), $headers);
    }

    /**
     * @return array{request_id: string, server_received_at: string}
     */
    public static function envelope(Request $request): array
    {
        if (! $request->attributes->has('request_id')) {
            $request->attributes->set('request_id', (string) Str::uuid7());
        }

        if (! $request->attributes->has('server_received_at')) {
            $request->attributes->set('server_received_at', CarbonImmutable::now());
        }

        return [
            'request_id' => $request->attributes->get('request_id'),
            'server_received_at' => Rfc3339::format($request->attributes->get('server_received_at')),
        ];
    }
}

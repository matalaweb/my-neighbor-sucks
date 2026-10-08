<?php

namespace App\Http\Middleware;

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the request-size limit before parsing (spec §7). Optional gzip is
 * inflated incrementally and must also obey the decompressed limit, so a
 * compression bomb is rejected without materializing it.
 */
class LimitDevicePayload
{
    private const INFLATE_CHUNK_BYTES = 1024;

    public function handle(Request $request, Closure $next, ?string $limit = null): Response
    {
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $maxBytes = (int) ($limit ?? config('noise.device_api.max_request_bytes'));
        $declaredLength = $request->headers->get('Content-Length');

        if ($declaredLength !== null && (int) $declaredLength > $maxBytes) {
            $this->tooLarge($maxBytes);
        }

        $raw = $this->readBounded($request, $maxBytes);
        $encoding = strtolower(trim((string) $request->headers->get('Content-Encoding', '')));

        $body = match ($encoding) {
            '', 'identity' => $raw,
            'gzip' => $this->inflate($raw, $maxBytes),
            default => throw new DeviceApiException(ErrorCode::ValidationFailed, 'Unsupported Content-Encoding; use gzip or none.'),
        };

        try {
            $decoded = $body === '' ? null : json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new DeviceApiException(ErrorCode::MalformedJson, 'Request body is not valid JSON: '.$exception->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new DeviceApiException(ErrorCode::MalformedJson, 'Request body must be a JSON object.');
        }

        $request->headers->set('Content-Type', 'application/json');
        $request->setJson(new InputBag($decoded));
        $request->attributes->set('device_payload', $decoded);

        return $next($request);
    }

    private function readBounded(Request $request, int $maxBytes): string
    {
        $stream = $request->getContent(true);
        $buffer = '';

        while (! feof($stream)) {
            $chunk = fread($stream, 65536);

            if ($chunk === false) {
                break;
            }

            $buffer .= $chunk;

            if (strlen($buffer) > $maxBytes) {
                $this->tooLarge($maxBytes);
            }
        }

        return $buffer;
    }

    private function inflate(string $compressed, int $maxBytes): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $output = '';

        foreach (str_split($compressed, self::INFLATE_CHUNK_BYTES) as $chunk) {
            $piece = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);

            if ($piece === false) {
                throw new DeviceApiException(ErrorCode::MalformedJson, 'Request body is not valid gzip data.');
            }

            $output .= $piece;

            if (strlen($output) > $maxBytes) {
                $this->tooLarge($maxBytes, decompressed: true);
            }
        }

        $tail = @inflate_add($context, '', ZLIB_FINISH);

        if ($tail === false) {
            throw new DeviceApiException(ErrorCode::MalformedJson, 'Request body is not valid gzip data.');
        }

        $output .= $tail;

        if (strlen($output) > $maxBytes) {
            $this->tooLarge($maxBytes, decompressed: true);
        }

        return $output;
    }

    private function tooLarge(int $maxBytes, bool $decompressed = false): never
    {
        throw new DeviceApiException(
            ErrorCode::PayloadTooLarge,
            ($decompressed ? 'Decompressed request body' : 'Request body').' exceeds '.$maxBytes.' bytes; split the batch.',
            ['max_bytes' => $maxBytes],
        );
    }
}

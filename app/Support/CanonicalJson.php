<?php

namespace App\Support;

use JsonException;

/**
 * Deterministic JSON encoding used for payload hashes (documented in
 * docs/architecture/0002-idempotency-and-hashing.md):
 *
 *  - object keys sorted by byte order (recursively); list order preserved
 *  - numbers: integers as integers, floats in shortest round-trip form with
 *    a preserved ".0" fraction, so 78.4 and 78.40 hash identically
 *  - strings UTF-8, unescaped slashes and unicode
 *  - no insignificant whitespace
 *
 * The hash is lowercase hex SHA-256 of the UTF-8 encoded canonical text.
 */
final class CanonicalJson
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function binaryHash(mixed $value): string
    {
        return hash('sha256', self::encode($value), true);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        $normalized = array_map(self::normalize(...), $value);

        // Force an empty map to encode as {} rather than [].
        return $normalized === [] ? new \stdClass : $normalized;
    }
}

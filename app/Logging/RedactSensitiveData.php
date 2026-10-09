<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog tap that redacts device credentials, bearer headers, presigned
 * URL signatures, invitation tokens, and public share links from log messages and context.
 */
class RedactSensitiveData
{
    private const PATTERNS = [
        '/nmd_[A-Za-z0-9]{8,}/' => 'nmd_[REDACTED]',
        '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i' => '$1[REDACTED]',
        '/(X-Amz-(?:Signature|Credential|Security-Token)=)[^&\s"\']+/i' => '$1[REDACTED]',
        '/(invitations\/)[A-Za-z0-9]{20,}/' => '$1[REDACTED]',
        '/nms_[A-Za-z0-9]{8,}/' => 'nms_[REDACTED]',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                message: self::redact($record->message),
                context: self::redactArray($record->context),
            ));
        }
    }

    public static function redact(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/authorization|password|token|secret|signature/i', $key) === 1) {
                $values[$key] = '[REDACTED]';
            } elseif (is_string($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $values[$key] = self::redactArray($value);
            }
        }

        return $values;
    }
}

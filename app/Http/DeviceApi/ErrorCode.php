<?php

namespace App\Http\DeviceApi;

/**
 * Device API error codes. "retry" tells the agent what to do:
 *  - backoff: retry with exponential backoff + jitter using the same identities
 *  - after_correction: do not retry automatically; quarantine locally
 *  - after_clock_sync: fix the clock, then the agent may resubmit
 *  - after_configuration_refresh: refetch configuration before resubmitting
 *  - never: permanent rejection; quarantine locally, never auto-retry
 */
enum ErrorCode: string
{
    case InvalidCredentials = 'invalid_credentials';
    case ForbiddenAbility = 'forbidden_ability';
    case NotFound = 'not_found';
    case BatchConflict = 'batch_conflict';
    case MeasurementConflict = 'measurement_conflict';
    case EventRevisionConflict = 'event_revision_conflict';
    case EventTerminal = 'event_terminal';
    case RecordingConflict = 'recording_conflict';
    case RecordingAlreadyVerified = 'recording_already_verified';
    case UploadAttemptMismatch = 'upload_attempt_mismatch';
    case PayloadTooLarge = 'payload_too_large';
    case MalformedJson = 'malformed_json';
    case ValidationFailed = 'validation_failed';
    case UnsupportedSchemaVersion = 'unsupported_schema_version';
    case ClockFutureTimestamp = 'clock_future_timestamp';
    case OutsideBackfillWindow = 'outside_backfill_window';
    case UnknownProvenance = 'unknown_provenance';
    case RecordingsDisabled = 'recordings_disabled';
    case RateLimited = 'rate_limited';
    case ServiceUnavailable = 'service_unavailable';
    case InternalError = 'internal_error';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials => 401,
            self::ForbiddenAbility => 403,
            self::NotFound => 404,
            self::BatchConflict, self::MeasurementConflict, self::EventRevisionConflict, self::EventTerminal,
            self::RecordingConflict, self::RecordingAlreadyVerified, self::UploadAttemptMismatch => 409,
            self::PayloadTooLarge => 413,
            self::MalformedJson, self::ValidationFailed, self::UnsupportedSchemaVersion, self::ClockFutureTimestamp,
            self::OutsideBackfillWindow, self::UnknownProvenance, self::RecordingsDisabled => 422,
            self::RateLimited => 429,
            self::ServiceUnavailable => 503,
            self::InternalError => 500,
        };
    }

    public function retry(): string
    {
        return match ($this) {
            self::RateLimited, self::ServiceUnavailable, self::InternalError => 'backoff',
            self::ClockFutureTimestamp => 'after_clock_sync',
            self::UnknownProvenance => 'after_configuration_refresh',
            self::InvalidCredentials, self::ForbiddenAbility => 'after_correction',
            self::RecordingAlreadyVerified => 'never',
            default => 'never',
        };
    }

    /** Permanent rejections must be quarantined locally, never silently dropped. */
    public function permanent(): bool
    {
        return $this->retry() !== 'backoff';
    }
}

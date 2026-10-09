<?php

/*
|--------------------------------------------------------------------------
| Noise Monitor domain configuration
|--------------------------------------------------------------------------
|
| Defaults follow the web application handoff specification v1.0. Values
| that an owner may change per account live in accounts.settings and fall
| back to the defaults below.
|
*/

return [

    'default_timezone' => env('NOISE_DEFAULT_TIMEZONE', 'America/Chicago'),

    'storage' => [
        // Private object storage for recordings, exports, and attachments.
        // Laravel Cloud attaches the private bucket as the default disk.
        'disk' => env('NOISE_STORAGE_DISK', env('FILESYSTEM_DISK', 's3')),
        // Optional alternate endpoints used only for signing URLs. Both are
        // empty in production, where every client reaches the same endpoint.
        'browser_endpoint' => env('AWS_BROWSER_ENDPOINT'),
        'device_endpoint' => env('AWS_DEVICE_ENDPOINT'),
    ],

    'device_api' => [
        'schema_version' => 1,
        'max_records_per_batch' => 300,
        'max_request_bytes' => 1024 * 1024,
        // Measurement batches: 60 requests/minute with a burst of 10.
        'measurement_rate_per_minute' => 60,
        'measurement_burst' => 10,
        'control_rate_per_minute' => 30,
        'recording_rate_per_minute' => 60,
        'future_tolerance_seconds' => 300,
        'backfill_days' => 30,
        'credential_rotation_hours' => 24,
        'last_used_write_interval_seconds' => 60,
    ],

    'measurements' => [
        'interval_ms' => 1000,
        'quality_policy_version' => 'qp-2026-10-v1',
        'metric_min_db' => -200.0,
        'metric_max_db' => 250.0,
        'dbfs_min' => -200.0,
        'dbfs_max' => 0.0,
        'third_octave_centers_hz' => [20, 25, 31.5, 40, 50, 63, 80, 100, 125],
    ],

    'events' => [
        'snapshot_pre_seconds' => 10,
        'snapshot_post_seconds' => 30,
        // A finalized event whose snapshot is still incomplete is frozen as
        // partial after this many hours; later readings create a new version.
        'snapshot_settle_hours' => 24,
        // A finalized event expecting audio with no declaration after this
        // long is shown as recording "missing" (a later declaration recovers).
        'recording_missing_after_hours' => 72,
    ],

    'recordings' => [
        'max_bytes' => 100 * 1024 * 1024,
        'max_duration_ms' => 10 * 60 * 1000,
        'duration_tolerance_ms' => 50,
        'upload_url_ttl_minutes' => 15,
        'playback_url_ttl_minutes' => 5,
        'staging_retention_hours' => 24,
        'mime_types' => ['audio/wav', 'audio/x-wav', 'audio/flac'],
    ],

    'exports' => [
        'max_days' => 31,
        'max_events' => 500,
        'expiry_days' => 7,
        'download_url_ttl_minutes' => 5,
        'large_bundle_warning_bytes' => 500 * 1024 * 1024,
    ],

    // Public, read-only dashboard links for devices an owner shares.
    'sharing' => [
        'requests_per_minute' => 60,
        'cache_seconds' => 15,
        'poll_seconds' => 30,
    ],

    'dashboard' => [
        'poll_seconds' => 15,
        'max_points_per_series' => 2000,
        'stale_grace_seconds' => 30,
        'offline_missed_heartbeats' => 3,
    ],

    'device_defaults' => [
        'reporting_interval_seconds' => 30,
        'heartbeat_interval_seconds' => 60,
        'pre_roll_seconds' => 10,
        'post_roll_seconds' => 30,
        'max_segment_duration_seconds' => 600,
    ],

    // Owner-configurable per account (accounts.settings.retention).
    'retention' => [
        'raw_measurements_days' => 30,
        'minute_rollups_days' => 365,
        'hour_rollups_days' => null, // retained until owner deletion
        'events_days' => 365,
        'recordings_days' => 90,
        'heartbeats_days' => 7,
        'health_summaries_days' => 90,
        'exports_days' => 7,
        'staging_hours' => 24,
        'replay_receipts_days' => 90,
    ],

    'invitations' => [
        'expiry_days' => 7,
    ],

];

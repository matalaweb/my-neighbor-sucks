# Known limitations and deviations

This list is deliberately candid. None of these items weaken idempotency, provenance, account isolation or energy-correct aggregation.

## What the system can and cannot claim

- **DIY monitoring only.** The app does not provide certified, regulatory or legally conclusive measurements. It never attributes a source automatically. "Confirmed disturbance" records a reviewer's judgement.
- **Hashes show byte equality only.** Export manifests and recording SHA-256 values show that the exported bytes match what the server retained. They do not prove that the capture is authentic, that the device clock was right, or what the source was.
- **The audit log is not tamper-proof.** `audit_logs` provides traceability within the application. It is not a chain-of-custody guarantee.
- **Calibration** is whatever the owner documents. No accuracy or uncertainty value is ever computed or shown.

## Device API

- **The burst limit is a fixed window.** The measurement limit is 60 per minute, plus 10 per 10 seconds per device, both as fixed windows. That approximates "burst 10"; it is not a token bucket.
- **Shared control limit.** Heartbeat, configuration fetch and configuration acknowledgments share one limit of 30 per minute per device. That is ample at the default intervals.
- **schema_version and bodiless routes.** Every request *body* carries `schema_version`, but `GET` routes and `POST recordings/{id}/upload-attempts` have no body and therefore no `schema_version`.
- **MVP interval constraint.** Measurements must cover exactly 1,000 ms aligned to UTC second boundaries. Other interval lengths are rejected rather than stretched.

## Measurements and aggregation

- **Quality policy choices.** Policy `qp-2026-10-v1` excludes clipping, dropouts, a disconnected microphone, incomplete intervals, processing errors, below-noise-floor values and unsynchronized-clock intervals from all summaries. Invalid calibration excludes only the absolute metrics. These are judgement calls, documented in [ADR 0003](architecture/0003-aggregation-and-quality-policy.md). Raw values are always kept.
- **Overlap detection assumes aligned 1 s intervals.** Ambiguous overlap means more than one row with the same capture second on a channel. Detection relies on the MVP's 1 s alignment. Overlapping intervals are excluded only in rollups; the raw-second chart view shows each stream's rows without that exclusion.
- **Third-octave bands** appear only when the agent supplies them and the profile defines them.
- **Near real time, not live.** The dashboard polls every 15 s while visible; there are no WebSockets.
- **No notifications** of any kind, by design for the MVP.

## Audio

- **Originals are served as-is.** WAV/FLAC originals are played directly through short-lived presigned URLs (browsers support both). No compressed playback derivative is generated, and there is no ffmpeg dependency. The `derivative_*` columns on `event_recordings` are reserved for that later.
- **No waveform** visualization is implemented.
- Initial formats are mono only.

## Events and review

- **Grouping** has a minimal UI: an "Add to group" action and group badges on the event page. There is no group browsing screen.
- **Late recordings.** A recording that arrives after its event was marked `missing` moves the event back to pending/verifying. Before the reconciler's 72 h pass, an event that expects a recording simply shows *Pending upload*.

## Exports

- **PDF bytes vary between builds.** The PDF embeds its generation time, so a rebuild after a partial failure produces different bytes. A finished (`ready`) export is never rebuilt; a new export creates a new version.
- **Date-range bundles contain only the events in the range.** Full one-second readings for a range come from the separate CSV export.
- Large exports are estimated and queued but not blocked; the owner override only lifts the 31-day and 500-event limits.

## Storage and infrastructure

- **Local S3 uses RustFS.** MinIO community images are no longer pullable, so the local stack uses RustFS (S3-compatible); see [ADR 0005](architecture/0005-local-s3-rustfs.md). Production uses Laravel Cloud Object Storage (R2).
- **Attachment uploads assume one replica.** Livewire temporary uploads use the local disk (`LIVEWIRE_TMP_DISK=local`), which assumes the upload and the form submission are handled by the same replica.
- **No database partitioning.** Measurements are not partitioned; per the spec, this is deferred until measured query or retention behaviour justifies it.
- **Benchmark scope.** Benchmark numbers come from a local Docker environment on a developer machine, not from Laravel Cloud. See [docs/benchmarks.md](benchmarks.md).

## Deferred by specification (§21)

Not implemented: automatic engine classification, AI summaries, speech processing, vibration, camera correlation, multi-property maps, native apps, public sharing links, billing, self-service signup, live streaming, remote software updates and external notifications.

# ADR 0001 — Calibration states and measurement provenance

Status: accepted (2026-10-08); amended 2026-10-09 — device-reported measurement chain

## Context

Readings come from a DIY Raspberry Pi measurement chain, not a certified
meter. Reports must describe the actual equipment and calibration status, and
must never present uncalibrated digital levels as sound pressure levels.

## Decision

* Every reading is tied to immutable provenance owned by the same device:
  a **deployment** (placement revision), a **measurement profile**
  (device/channel processing settings), an optional **calibration**, and the
  **configuration revision** in force. Models: `DeviceDeployment`,
  `MeasurementProfile`, `DeviceCalibration`, `DeviceConfiguration`
  (`app/Models`). These rows are immutable (`IsImmutable` trait); changes create
  a new revision via `App\Services\Devices\ProvenanceRecords`, carrying a
  canonical content hash, and apply prospectively only.
* **The device is the source of truth for its measurement chain**
  (amendment 2026-10-09). Owners used to create profiles and calibrations and
  reference them, with a placement, in every configuration; the Pi then had to
  match those records against its own hardware, which repeatedly failed. Now:
  * The Pi registers its profiles and calibrations with
    `POST /api/v1/device/provenance` (`App\Services\Devices\RegisterDeviceProvenance`)
    under device-generated UUIDs. Records are stored with `source = device`
    (`App\Enums\ProvenanceSource`), no `created_by`, and the next per-channel
    revision. The server hashes each record canonically (without `id`, with
    each attachment's purpose, filename and SHA-256, not its bytes): identical
    content under a known id is a no-op; different content, or an id another
    device registered, is `409 provenance_conflict` and nothing is stored.
    Calibration files (e.g. the UMIK-1 frequency-response file) travel inline
    (base64, ≤ 1 MiB each, SHA-256 verified) and are stored like owner uploads.
  * Owners no longer create profiles or calibrations; the panel shows them
    read-only with their source. Owner-created records remain valid history
    and may still be referenced by readings. Owners still record calibration
    field checks and supporting documents (not frequency-response files).
  * **Placements stay owner-entered.** Devices never send one: each reading
    (event: its `started_at`) gets the deployment with the greatest
    `effective_at <= captured_at`, or none ("placement not recorded"). A
    `deployment_id` sent by an older agent is accepted and ignored. The former
    "capture precedes deployment" rejection is gone.
  * The Pi measures before any configuration is published, on local defaults;
    such readings and events carry `configuration_revision = null` (shown as
    "local defaults"). Configurations carry operational settings only
    (`channel`, `enabled`, `metrics`, `bands_enabled` per channel), are
    validated against the device's reported capabilities, and
    `GET /configuration` no longer returns `provenance`. The device-facing
    calibration attachment download was removed.
* Raw measurements store `stream_id` + `configuration_revision` (nullable). A
  `measurement_streams` row is the identity device + channel + deployment
  (nullable) + profile + calibration (`MeasurementStream::keyFor`). Series from
  different streams are never averaged together, so a change of room,
  microphone or calibration splits the chart series.
* Calibration states (`App\Enums\CalibrationState`):
  * `uncalibrated` — only `rms_dbfs` may be non-null; absolute SPL fields must
    be null and no calibration may be referenced.
  * `estimated` — absolute SPL accepted, visibly labelled as an estimate;
    requires a calibration record whose state is `estimated`.
  * `calibrated` — a documented measurement chain (e.g. acoustic calibrator,
    sensitivity, gain), not a certified instrument; requires a calibration
    record whose state is `calibrated`.
  Profile and calibration states must match, so estimated and calibrated
  data can never be silently combined. Enforced on ingestion by
  `App\Services\Ingestion\ProvenanceResolver::check`; registration rejects
  uncalibrated calibrations and SPL metrics on uncalibrated profiles.
* A frequency-response file is stored as a checksummed private attachment
  (`attachments` table) but never by itself establishes absolute SPL
  calibration. Sensitivity/reference details, gain configuration,
  application method and field checks (`calibration_field_checks`) are
  recorded. No accuracy or uncertainty value is stored or invented.
* Profiles declare supported metrics, optional low-frequency band edges and
  third-octave band definitions. A supported metric reported as null needs a
  `null_reasons` entry or a quality flag; unsupported metrics must be null.
  Bands outside the profile definition are rejected. The 20–125 Hz
  band-limited metric is supplied by the agent; the server never sums
  third-octave bands to derive it.

## Consequences

* Ingestion rejects readings referencing unknown profiles, calibrations or
  configuration revisions with `422 unknown_provenance`
  (`retry: after_configuration_refresh`); the agent re-registers its chain and
  resubmits. Nothing has to be kept "matching" by hand.
* Readings can exist without a placement or configuration; UI and exports say
  "placement not recorded" / "local defaults" instead of inventing one.
  Placements added later apply to readings captured after their effective
  time only; stored readings keep the stream they were ingested under.
* Reports can always list the exact equipment, placement and calibration
  chain behind each value, including when it changed.

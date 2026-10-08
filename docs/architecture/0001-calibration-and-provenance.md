# ADR 0001 — Calibration states and measurement provenance

Status: accepted (2026-10-08)

## Context

Readings come from a DIY Raspberry Pi measurement chain, not a certified
meter. Reports must describe the actual equipment and calibration status, and
must never present uncalibrated digital levels as sound pressure levels.

## Decision

* Every reading references immutable provenance owned by the same device:
  a **deployment** (placement revision), a **measurement profile**
  (device/channel processing settings), an optional **calibration**, and the
  **configuration revision** in force. Models: `DeviceDeployment`,
  `MeasurementProfile`, `DeviceCalibration`, `DeviceConfiguration`
  (`app/Models`). These rows are immutable (`IsImmutable` trait); changes create
  a new revision via `App\Services\Devices\ProvenanceRecords`, carrying a
  canonical content hash, and apply prospectively only.
* Raw measurements store `stream_id` + `configuration_revision`. A
  `measurement_streams` row is the identity device + channel + deployment +
  profile + calibration (`MeasurementStream::keyFor`). Series from different
  streams are never averaged together, so a change of room, microphone or
  calibration splits the chart series.
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
  `App\Services\Ingestion\ProvenanceResolver::check` and on configuration
  publishing by `DeviceConfigurationService::validate`.
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

* Ingestion rejects readings referencing unknown provenance with
  `422 unknown_provenance` (`retry: after_configuration_refresh`); owners must
  provision records before the device uses them. `GET /configuration`
  returns the referenced records under `provenance`.
* Reports can always list the exact equipment, placement and calibration
  chain behind each value, including when it changed.

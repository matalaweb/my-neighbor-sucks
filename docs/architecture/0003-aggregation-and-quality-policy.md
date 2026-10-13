# ADR 0003 — Aggregation and quality policy

Status: accepted (2026-10-08)

## Energy averaging

Decibels are never averaged arithmetically (`App\Support\Decibels`). For
valid intervals *i* with level Lᵢ and duration tᵢ (ms):

```
energy_sum     = Σ tᵢ · 10^(Lᵢ/10)
valid_duration = Σ tᵢ
Leq            = 10 · log10(energy_sum / valid_duration)     (null if valid_duration = 0)
```

Regression values (tested in `tests/Unit/DecibelsTest.php`): 1 s at 40 dBA +
1 s at 60 dBA → 57.03 dBA; 1 s at 40 + 3 s at 60 → 58.77 dBA (58.765).
Missing time contributes neither energy nor duration.

* Energy metrics: LAeq, LCeq, low-frequency Leq, RMS dBFS. Rollups store
  `<m>_energy_sum`, `<m>_valid_ms`, `<m>_excluded_ms` per metric so hours
  combine exactly from minutes.
* Maximum metrics: LAFmax, LCpeak aggregate with `max()` only
  (`<m>_max`, `<m>_valid_ms`, `<m>_excluded_ms`). LAFmax is never derived from
  LAeq.
* Third-octave bands: each (centre, weighting) is energy-averaged over time;
  bands are never summed across frequency, and differing definitions are
  never combined.

## Rollups (`App\Services\Measurements\RebuildRollups`)

* UTC-aligned minute (60 s) and hour (3600 s) buckets per stream.
* A minute is always recomputed from authoritative raw rows for the whole
  device/channel minute; an hour is recomputed from its minute rollups.
* Late or out-of-order data marks the minute dirty in `maintenance_jobs` in
  the ingestion transaction; rebuilding a minute marks its hour dirty.
  Backfill therefore repairs historical buckets, not just today.
* Dirty markers carry a generation counter. A worker deletes a marker only if
  its generation did not change during the rebuild, and a running marker is
  never claimed by another worker (`App\Services\Maintenance\MaintenanceQueue`).
  The scheduled reconciler re-dispatches anything left behind.

## Coverage

* `expected_ms` is the bucket length; `covered_ms` counts distinct
  unambiguous intervals with a row; `<m>_valid_ms` counts intervals usable
  for that metric; `excluded_ms` counts whole intervals excluded by policy;
  `ambiguous_ms` counts intervals reported more than once.
* Missing time is unknown — not silence and not 0 dB. Displays say e.g.
  "LAeq over 52 measured minutes of 60"; daily figures never imply 24 hours
  of coverage when data is missing. Charts render gaps as gaps.

## Quality policy `qp-2026-10-v1` (`App\Services\Measurements\QualityPolicy`)

Raw values and flags are always preserved; the policy only governs official
summaries (rollups, server event summaries).

| Flag | Effect |
| - | - |
| `microphone_disconnected`, `audio_dropout`, `incomplete_interval`, `processing_error` | exclude every metric (value missing/partial) |
| `clipping` | exclude every metric (values are lower bounds) |
| `unsynchronized_clock` | exclude every metric (timing untrusted, cannot be placed in a trusted bucket) |
| `below_noise_floor` | exclude every metric (value is an upper bound) |
| `invalid_calibration` | exclude absolute SPL metrics; keep `rms_dbfs` |
| ambiguous overlap | two rows (different boots or streams) for the same channel interval: all excluded and counted in `ambiguous_ms` / `quality_counts.ambiguous_overlap` |

Null metrics are neither valid nor excluded. Bands follow the policy for
absolute metrics. The policy version is stored on every rollup and snapshot;
changing the policy requires a new version and a rebuild.

## Calibration separation

Streams are keyed by calibration identity, so estimated and calibrated series
are separate rollups and separate chart series.

## Time zones

Storage and bucketing are UTC. The property time zone (default
`America/Chicago`) is used for date selection and display only
(`App\Support\LocalTime`). A local calendar day is converted to a UTC
`[start, end)` range, so daylight-saving days are 23 or 25 hours long; local
timestamps are displayed with zone abbreviation and offset to disambiguate
repeated hours.

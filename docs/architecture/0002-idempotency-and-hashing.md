# ADR 0002 — Idempotency and canonical hashing

Status: accepted (2026-10-08)

## Context

The Pi uploads every 30–60 s, replays offline backlogs, and retries after
lost acknowledgments, restarts, and clock problems. Retries must never
duplicate readings, and changed data under an existing identity must never be
hidden.

## Decision

### Identities (enforced by unique indexes)

| Thing | Identity | Index |
| - | - | - |
| Batch | device + `batch_id` | `measurement_batches (device_id, uuid)` |
| Reading | device + channel + `boot_id` + `sequence` | `measurements_identity_unique` |
| Event | device + `event_id` | `noise_events (device_id, uuid)` |
| Event revision | event + `revision` | `noise_event_revisions (noise_event_id, revision)` |
| Recording | device + `recording_id`; event + `segment_number` | `event_recordings` uniques |
| Configuration | device + `revision` | `device_configurations (device_id, revision)` |

### Canonical hash (`App\Support\CanonicalJson`)

Values are parsed and normalized first (UUIDs lowercased, timestamps to UTC
`Y-m-d\TH:i:s.u\Z`, metric numbers to floats, missing metric = null, flags
sorted/de-duplicated, `null_reasons` keys sorted, bands sorted by centre).
The canonical JSON sorts object keys by byte order recursively, preserves list
order, has no whitespace, and encodes floats in shortest round-trip form with a
`.0` fraction preserved. The hash is lowercase hex SHA-256 of the UTF-8 text.

* Row hash: canonical record (`MeasurementBatchParser::parseRecord`), stored
  as `measurements.row_hash` (binary 32).
* Batch hash (`MeasurementBatchData::payloadHash`): `{schema_version,
  batch_id, records}` with records sorted by (channel, boot_id, sequence).
  `sent_at` is excluded because it is transport metadata.
* Event revision hash: the canonical revision (`EventPayloadParser`).
* Recording declaration hash and configuration document hash likewise. The
  configuration hash is computed on the document's stored form (a JSON round
  trip, exactly what `GET /configuration` serves), so an agent can verify it
  by re-encoding the received document. Revisions published before
  2026-10-09 hashed the in-memory build, in which whole-number dB settings
  were floats (`15.0`, served as `15`); they are left unchanged (immutable).

### Batch ingestion (`App\Services\Ingestion\IngestMeasurements`)

1. Validate everything (no partial acceptance).
2. Fast path: an existing batch with the same hash returns the stored result
   (`200`, `replayed: true`); a different hash returns `409 batch_conflict`.
3. In one transaction: insert the batch receipt (a concurrent duplicate hits
   the unique key → handled as a replay after the winner commits); bulk
   `INSERT … ON DUPLICATE KEY UPDATE id = id` the rows; then a locking read
   (`FOR SHARE`) of every identity in the batch. Rows owned by this batch were
   inserted; rows owned by another batch are duplicates if the hash matches
   and conflicts otherwise. Any conflict rolls back the whole batch
   (`409 measurement_conflict`). Insert-ignore is never used to hide changes.
4. `measurement_receipts` (compact identity + hash) retained after raw rows are
   purged are checked in the same transaction so purged readings cannot be
   resurrected; a changed payload against a receipt is a conflict.
5. Dirty-bucket and event-snapshot outbox markers are written in the same
   transaction; the 2xx response is returned only after commit.

Deadlocks are retried by `DB::transaction(..., attempts: 3)`; records are
sorted by identity to keep lock order stable.

### Events

The event row is locked `FOR UPDATE`; an existing revision is compared by
hash (identical → `duplicate`; different → `409`). Older unseen revisions are
stored with `applied_to_projection = false`; finalized events are terminal.

## Consequences

Agents can blindly retry with the same identities. Concurrency is resolved by
the database, not by check-then-insert. Receipts (`measurement_batches`,
`measurement_receipts`) are retained ≥ 90 days, longer than the 30-day replay
window.

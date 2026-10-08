# ADR 0005 — Local S3-compatible storage with RustFS

Status: accepted (2026-10-08)

## Context

Local development must exercise real presigned PUT/GET, range requests and
streaming against an S3-compatible API. The MinIO community images
(`minio/minio`, `quay.io/minio/minio`, `minio/mc`) can no longer be pulled.
Production uses Laravel Cloud Object Storage (Cloudflare R2) attached as the
environment's default (private) disk.

## Decision

* The compose stack runs `rustfs/rustfs` as service `s3` (API on
  container port 9000, forwarded to `localhost:9100`; console on 9101).
  Credentials come from `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` and only
  work locally.
* Buckets are created with `php artisan noise:storage:ensure-buckets`
  (refuses to run in production): `noise-monitor` and `noise-monitor-test`.
* Application code always uses the configured private disk
  (`config('noise.storage.disk')`, defaulting to `FILESYSTEM_DISK`) through
  `App\Services\Storage\EvidenceStorage`.
* Containers reach storage at `http://s3:9000`, but browsers and devices may
  not. URL signing is offline, so `EvidenceStorage` signs with an alternate
  endpoint when configured:
  * `AWS_BROWSER_ENDPOINT` (local: `http://localhost:9100`) for playback and
    export downloads;
  * `AWS_DEVICE_ENDPOINT` (empty by default → `AWS_ENDPOINT`; set to a
    LAN-reachable address when a real Pi uploads to the local stack).
  Both are empty in production, where everyone uses the same endpoint.
* Path-style addressing is enabled locally (`AWS_USE_PATH_STYLE_ENDPOINT=true`).

## Consequences

* The same S3 code paths (presigned PUT, streamed reads, Range GET) are
  exercised locally and in the real-S3 integration tests.
* RustFS does not prove R2-specific behaviour; deployment verification on
  Laravel Cloud must re-check presigned PUT and Range playback.
* The application never relies on object versioning (see ADR 0004).

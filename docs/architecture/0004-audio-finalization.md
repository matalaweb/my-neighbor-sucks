# ADR 0004 — Recording upload and audio finalization

Status: accepted (2026-10-08)

## Context

Event clips are evidence. Devices upload directly to private S3-compatible
storage (Laravel Cloud Object Storage is Cloudflare R2, which has no object
versioning). A presigned PUT URL can be reused until it expires, so a staging
object may change after it was checked. S3 ETags are not SHA-256 digests.

## Decision

1. **Declaration** (`IssueRecordingUpload::declare`): immutable recording UUID,
   segment, capture start, duration, MIME/codec, sample rate, channels, bit
   depth, byte size and source SHA-256. A canonical declaration hash makes
   re-declaration idempotent and changed declarations a `409`.
2. **Upload attempt**: the server generates the staging key
   `staging/{account}/{device}/{recording}/{attempt}.{ext}` and returns a
   15-minute presigned PUT. Devices never choose buckets, prefixes, ACLs or
   final keys. New attempts supersede unfinished ones.
3. **Completion, stage one** (`CompleteRecordingUpload`): records the exact
   attempt, sets the recording `uploaded`, writes a `verify_recording`
   outbox record, and returns `202`.
4. **Verification, stage two** (`VerifyRecording`, `recordings` queue):
   stream the staging object **once** into a local temporary file while
   hashing those same bytes (`EvidenceStorage::downloadAndHash`); compare
   length, SHA-256, container/codec/sample rate/channels/bit depth and
   duration (±50 ms) using `MediaInspector` (WAV RIFF and FLAC STREAMINFO
   parsing, no transcoding). On success write **the same local bytes** to a
   new server-owned final key
   `recordings/{account}/{device}/{event}/{recording}/{attempt}.{ext}`, check
   the final size, then commit `verified_sha256`, `final_key`, media info and
   `status = verified` in one transaction under a row lock. If another attempt
   won meanwhile, the extra copy is deleted.
5. Mismatches set the attempt and recording to `failed` with a reason and the
   computed hash; the declared metadata is never rewritten to make it pass. The
   agent may request a new attempt.
6. The agent keeps its local clip until `GET /recordings/{id}` reports
   `verified`.
7. Staging objects are deleted after verification; abandoned ones are cleaned
   after 24 hours. Object-store lifecycle rules may only target `staging/`.

## Consequences

* A reused or replaced staging URL can never change a verified original: the
  final key is written once per attempt from bytes that were hashed, and no
  device credential can write it.
* No dependency on provider object versioning or checksum headers, so the
  same code works on R2, S3, and RustFS.
* Browser playback uses short-lived presigned GETs of the verified original
  (HTTP Range supported). Derivatives, if added, are labelled and linked to the
  verified original; the original is never normalized or transcoded.
* Verification needs temporary local disk up to the 100 MiB segment limit.

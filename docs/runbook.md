# Operations runbook

This runbook covers both environments. Locally, run commands with `docker compose exec app php artisan …`. On Laravel Cloud, use `cloud command:run <environment> --cmd='php artisan …' -n`.

## Services and queues

| Service | Role | Local (compose) |
| - | - | - |
| Web | Filament UI, device API, health routes | `app` (FrankenPHP, :8088) |
| Rollups worker | Minute/hour rollups, event measurement snapshots (`rollups,default`) | `queue-default` |
| Recordings worker | Recording verification and finalization (`recordings`) | `queue-recordings` |
| Exports worker | PDF/CSV/ZIP builds (`exports`) | `queue-exports` |
| Scheduler | Reconciler, deep reconciliation, retention | `scheduler` (`schedule:work`) |
| MySQL 8.4 / Redis / S3 | Data, cache/sessions/queues, private objects | `mysql`, `redis`, `s3` (RustFS) |

Ingestion never depends on the workers. A batch is acknowledged only after its rows, receipt and outbox markers commit in one transaction. If the workers are down, data stays durable; derived work (rollups, snapshots, verification) resumes when they return.

## Durable outbox and reconciler

Required asynchronous work is recorded in `maintenance_jobs`. Its kinds are `rollup_minute`, `rollup_hour`, `event_snapshot`, `verify_recording` and `delete_object`. A marker is an upsert that increments a generation counter. A worker deletes the marker only if the generation hasn't changed, so data that arrives during a rebuild is never lost.

| Command | Schedule | Effect |
| - | - | - |
| `noise:reconcile` | Every minute | Re-dispatches workers for due outbox records. Rebuilds exports stuck in `pending`/`building` for over 15 minutes. Freezes snapshots past their settle period (24 h). Marks expected recordings as `missing` after 72 h. |
| `noise:reconcile --deep` | Hourly | Compares raw row counts with minute rollups for the last 26 h and re-marks any bucket that disagrees |

**Operational status** in the app shows queue sizes, outbox counts by kind/status with the oldest age, failed outbox records with their last error, failed queue jobs, recording verification failures, device API metrics for the last 24 h, and recent audit events. `/health/ready` reports the oldest pending outbox age and the failed count.

If the outbox is growing:

1. Check that the workers and scheduler are running.
2. Look at `last_error` on failed records in Operational status.
3. After fixing the cause, run `noise:reconcile`. Failed `rollup_*`/`event_snapshot` records return to pending the next time they are marked; `noise:reconcile --deep` re-marks rollups.

## Retention

```bash
php artisan noise:retention --dry-run           # counts only
php artisan noise:retention                     # all accounts
php artisan noise:retention --account=<uuid>
```

Defaults are in `config/noise.php` (`retention`); owners change them in **Account settings → Retention & recording policy**.

- Raw readings are deleted only after their rollups are durable and overlapping events have frozen (or partial) snapshots. Each deleted row leaves a compact replay receipt behind, so old retries cannot bring it back.
- Events marked **Keep** keep their snapshots, recordings, provenance and integrity metadata. Keep and purge transitions are serialized on the event row. Keeping an event cannot recover audio that was already purged.
- A purged recording keeps its identity and hashes as a tombstone (`recording.purged` in the audit log); only playback access is removed.
- Failed object deletions are stored as `delete_object` outbox records, retried on the next run, and listed in Operational status. The command exits non-zero if any account reported failures.
- The time of the last run is shown in Operational status (cache key `noise:retention:last_run`).

## Device credentials

| Task | Where |
| - | - |
| Issue (shown once) | Device → **Credentials → Issue credential** (at most 2 active credentials) |
| Rotate | **Rotate**: a new credential is issued; old ones keep working for the chosen window (0, 1, 24 or 72 h), then expire |
| Revoke | **Revoke**. Takes effect immediately, because every request re-reads the credential. |
| Lost or compromised device | **Archive device**. All credentials are revoked and ingestion stops; evidence is preserved. |

Rotation procedure: rotate with a 24 h window → install the new token on the Pi → confirm **Last used** updates on the new credential → revoke the old one (or let it expire). Device identity and history never change.

## Configuration

- **Configuration → Publish new revision** creates a complete, immutable document with a SHA-256 hash and validates it against the device's reported capabilities.
- A revision stays **Pending** until the device acknowledges it. A **Rejected** revision shows its reason, and the device keeps running its previous configuration.
- **Roll back to this** publishes a new revision that copies an older one; the history stays intact.
- Profile, placement and calibration changes apply going forward only. Create the new revision, then publish a configuration that references it.

## Reading device health

| Signal | Rule |
| - | - |
| Offline | No contact for 3 heartbeat intervals (default 3 × 60 s) |
| Stale readings | Latest capture older than 2 × reporting interval + 30 s (default 90 s) |
| Clock problem | Sync state isn't `synchronized`, or the offset exceeds 1 s |
| Storage pressure | Device disk more than 85% used |
| Config pending | Desired revision ≠ applied revision |

A device can be reachable (heartbeats arrive) while its microphone has failed or its readings are stale; each condition is shown separately. Capture time (device clock) and receipt time (server) are always shown separately. Readings that are clock-uncertain are stored with flags and excluded from summaries.

## Recording verification failures

A failed segment shows its reason on the event page and in Operational status. Reasons are `sha256_mismatch`, `size_mismatch`, `format_mismatch`, `codec_mismatch`, `sample_rate_mismatch`, `channel_count_mismatch`, `bit_depth_mismatch`, `duration_mismatch`, `unreadable_media` and `object_missing`. The declaration is never rewritten to make a check pass.

The agent's recovery path:

1. Poll `GET /api/v1/device/recordings/{id}`.
2. On `failed`, call `POST /api/v1/device/recordings/{id}/upload-attempts` to get a fresh staging key.
3. Upload again, then call `.../complete` with the new `attempt_id`.

The agent keeps its local clip until the status endpoint reports `verified`. Repeated failures usually mean the agent's declared hash or size is computed on different bytes than it uploads.

## Export failures

**Reports** shows `failed` with its reason. **Retry** rebuilds from the same frozen selection. Exports stuck in `pending`/`building` for over 15 minutes are re-dispatched by the reconciler. The build job re-checks that the requester may still export and fails otherwise. Downloads are 5-minute signed links and are audited (`export.downloaded`). Finished exports expire after 7 days by default.

## Backups

- **Laravel Cloud:** enable automated database backups on the MySQL cluster and record the retention period. Choose at least 14 days, and longer than you would take to notice a problem. Backups expire on the provider's schedule; this app does not manage them.
- **Object storage:** the bucket holds the only copy of the preserved audio originals, exports and attachments. R2 has no object versioning. If off-site copies are required, replicate `recordings/` and `attachments/` with an external sync tool on a schedule. `exports/` and `staging/` can be regenerated or discarded.
- Database rows reference objects by key and SHA-256 (`event_recordings.final_key`, `verified_sha256`; `attachments.object_key`, `sha256`), so a restore can be checked against stored hashes.

Local backup:

```bash
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines noise_monitor' > backup-$(date +%F).sql
```

### Restore procedure / drill

Run a drill quarterly against a scratch environment.

1. Restore the database backup into a fresh database. Locally: `docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" noise_monitor' < backup.sql`.
2. Point the app at it with the **same `APP_KEY`**, then run `php artisan migrate --force`. Migrations must report nothing pending or only additive steps.
3. Run `php artisan noise:reconcile --deep` to rebuild any rollups that disagree with raw rows.
4. Spot-check: open a kept event, play a verified recording, and generate an evidence bundle; check that the manifest hashes match `verified_sha256`.
5. Record the drill's date and its RPO/RTO.

## APP_KEY

`APP_KEY` encrypts property addresses (the `encrypted` cast on `properties.address`) and sessions. Rotating it without `APP_PREVIOUS_KEYS` makes stored addresses unreadable. To rotate:

1. Set `APP_PREVIOUS_KEYS` to the old key and `APP_KEY` to the new one, then deploy.
2. Re-save each property (or re-encrypt in a one-off command).
3. Remove the old key.

Device tokens and invitation tokens are SHA-256 digests and are not affected.

## Logs and redaction

Logging channels apply `App\Logging\RedactSensitiveData`, which redacts `nmd_` device tokens, `Bearer` headers, presigned-URL signatures and credentials, and invitation tokens, plus any context key matching authorization, password, token, secret or signature. Each device API request writes one structured `device_api_request` line with request ID, endpoint, device ID, status, latency and error code — never bodies. Responses carry `X-Request-Id`; ask the device operator for it when tracing an issue.

## Incident checklist

1. `/up` and `/health/ready`: which dependency is failing?
2. Operational status: queue sizes, outbox age, failed jobs, verification failures, API error rates.
3. Devices: offline? clock? backlog growing (`queued_measurement_count`, `oldest_pending_capture_at`)?
4. Device API 5xx: check database connectivity and deadlocks in logs (`service_unavailable` responses tell agents to back off and retry with the same identities).
5. 409 responses (`batch_conflict`, `measurement_conflict`, `event_revision_conflict`): the agent sent changed data under an existing identity. Fix the agent; never delete server rows to make a retry pass.
6. Suspected credential leak: rotate with a 0 h window, or archive the device.
7. After recovery: `noise:reconcile --deep`, then confirm the outbox drains.
8. Note the timeline, request IDs and actions in an incident record. The application audit log is traceability, not a tamper-proof chain of custody.

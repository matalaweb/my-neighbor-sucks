# Noise Monitor

## Why this exists

I hate my neighbor.

Not in a vague, passive-aggressive, "we should really talk sometime" way. I hate him in the specific, measurable, logged-to-the-microsecond way that only someone with a Raspberry Pi and a grudge can.

Here's the deal. My neighbor drives a shitbox. I don't know what's holding it together, but I know for a fact it isn't a muffler, because there isn't one. There's just a pipe. An open, screaming, rusted-out pipe that turns every cold start into an artillery barrage and every trip down the driveway into a NASCAR qualifying lap. The windows rattle. The dog loses her mind. Conversations stop mid-sentence. Sleep? Gone. Thanks, buddy.

And it's not just the car. It's how he drives it. He drives like a complete shithead. He doesn't idle out of the driveway like a normal human being. He revs it. He *revs* it, in the driveway, for no reason, like there's a crowd out there that is dying to hear what a lifetime of bad decisions sounds like at 7,000 RPM. Then he launches it down the street like he's late to his own sentencing hearing, and the whole goddamn neighborhood gets to enjoy the Doppler effect.

So I did the responsible thing. I went to the HOA.

The HOA — the same HOA that will send me a strongly worded letter if my trash can is visible from the street for eleven minutes past pickup — looked me dead in the eye and said, more or less, "We haven't noticed anything." Of course you haven't. You don't live here. You aren't woken up at 6:40 a.m. by a four-wheeled war crime warming up thirty feet from your bedroom. They don't believe me. They think I'm exaggerating. They think I'm *that* neighbor.

Fine. Fuck it. I'm an engineer. You want proof? I'll give you proof.

So I built this: a full-blown, multi-tenant, queue-backed, S3-archived acoustic surveillance platform with a documented OpenAPI contract, energy-correct decibel rollups, append-only review annotations, and tamper-evident evidence bundles with SHA-256 manifests. Is this proportionate? Absolutely not. Is it overkill? Spectacularly. Will the HOA have to sit down and read a PDF full of timestamped LAFmax spikes that line up perfectly with every time that shitbox turns over? **Yes. Yes they fucking will.**

Every rev. Every launch. Every 6:40 a.m. cold start. Measured, charted, recorded, hashed, and exported. Let's see you "not notice" that.

## What it actually does

A private Laravel application that receives sound measurements and event recordings from a Raspberry Pi 4B, makes disturbances easy to review, and exports traceable reports. The first installation monitors a home affected by loud vehicle noise from an adjacent driveway. The app works today with a simulated device; the Python capture agent is a separate project.

**What it is not.** Noise Monitor is a DIY monitoring system. Its readings do not come from a certified or regulatory sound level meter, they do not establish a legal violation, and the app never attributes a source (a vehicle, a neighbour) automatically. Reports describe the equipment and calibration status that was actually used. "Confirmed disturbance" means a reviewer confirmed that a disturbance happened, nothing more.

## Features

- **Device API** (`/api/v1/device`, OpenAPI 3.1): measurement batches, event revisions, two-stage recording upload with server-side verification, declarative versioned configuration, heartbeats. Idempotent by design (batch UUIDs, sequence identities, event revisions, recording UUIDs).
- **Measurements:** explicitly named metrics (LAeq, LAFmax, LCeq, LCpeak, low-frequency Leq, dBFS), quality flags, full provenance (placement, profile, calibration, configuration revision). Energy-correct minute and hour rollups with coverage, a versioned quality policy and gap-aware charts.
- **Events:** immutable agent revisions; separate detection, data-completeness, review and recording states; measurement snapshots; append-only annotations; keep flags.
- **Audio:** event clips only. Presigned uploads go to server-chosen staging keys, are verified independently with SHA-256 and media headers, and are copied to a server-owned final object. Playback uses short-lived authorized URLs.
- **Reports:** queued PDF summary, CSV measurements, and a ZIP evidence bundle with a SHA-256 manifest built from a frozen selection.
- **Retention:** per-account policies, staged and retryable deletion, purge tombstones, and keep flags that cannot race with deletion.
- **Public dashboard:** owners can share a device through a secret, revocable link (`/share/{token}`) to a read-only dashboard with live level, today's stats, charts and recent events with review status. It never shows audio, notes, source labels, placement or the property address; it is rate limited and kept out of search indexes.
- **Accounts:** an account (household) boundary with owner, reviewer and viewer roles, invitations, and no public registration.

## Architecture at a glance

| Layer | Choice |
| - | - |
| Runtime | PHP 8.4, Laravel 13 |
| UI | Filament 5 / Livewire 4 with tenancy per account (`/app/{account}`), Tailwind 4, Vite, Chart.js |
| Database | MySQL 8.4; all timestamps UTC with microseconds |
| Cache, sessions, queues | Redis (Laravel Cloud: Valkey) |
| Object storage | Private S3-compatible bucket: RustFS locally, Laravel Cloud Object Storage (R2) in production |
| Queues | `rollups,default` (rollups and snapshots), `recordings` (verification), `exports` (PDF/CSV/ZIP), so a large export can't delay new data |
| Scheduler | `noise:reconcile` every minute, `noise:reconcile --deep` hourly, `noise:retention` daily (`routes/console.php`) |

Domain logic lives in explicit services under `app/Services` (for example `Ingestion/IngestMeasurements`, `Events/IngestEventRevision`, `Recordings/IssueRecordingUpload`, `Recordings/VerifyRecording`, `Measurements/RebuildRollups`, `Exports/BuildEvidenceExport`, `Retention/ApplyRetention`). Required asynchronous work is recorded durably in `maintenance_jobs` and reconciled. Queue dispatches only wake the workers.

## Quick start (Docker)

You need Docker with Compose. Host PHP is not required: everything runs in the `noise-monitor-dev` image (PHP 8.4, Composer, Node 22, headless Chromium for browser tests).

```bash
docker compose up -d
```

That is all a fresh checkout needs. A one-shot `init` service runs first and the app, queue workers, and scheduler wait for it to finish. It:

1. creates `.env` from `.env.example` if missing (local-only credentials)
2. runs `composer install` and `npm ci`, then builds assets (only when they are missing or out of date)
3. generates `APP_KEY` if empty
4. runs migrations
5. creates the local private buckets

The first boot downloads dependencies and takes a few minutes. Follow it with `docker compose logs -f init`; later boots take seconds. `./docker/bootstrap.sh` does the same, rebuilds the image, and waits for `init`.

Create the first owner (public registration is disabled):

```bash
docker compose exec app php artisan noise:create-owner you@example.com --account="Household" --property="Home"
```

To create the owner automatically on first boot, set `NOISE_INITIAL_OWNER_EMAIL` (and optionally `NOISE_INITIAL_OWNER_PASSWORD`) in `.env` before `docker compose up`. With no password set, one is generated and printed in `docker compose logs init`.

Open <http://localhost:8088> and sign in.

Stop with `docker compose down`. Add `-v` to also delete the database, Redis, and object-storage volumes.

| Port (host) | Service |
| - | - |
| 8088 | App (FrankenPHP) |
| 33068 | MySQL 8.4 |
| 63790 | Redis |
| 9100 / 9101 | S3 API / RustFS console |
| 8126 | Mailpit (captured invitation and password-reset mail) |

All ports can be overridden with the `FORWARD_*` variables in `compose.yaml`.

## Demo device and simulator

```bash
docker compose exec app php artisan noise:demo:provision            # prints a device token once
docker compose exec app php artisan noise:simulate --token=nmd_... --scenario=all
docker compose exec app php artisan noise:simulate --token=nmd_... --scenario=day --hours=24 --speed=0
```

The simulator uses only the public device API. Its acoustic patterns are synthetic and labelled as such. See [docs/simulator.md](docs/simulator.md).

## Tests

```bash
docker compose exec app php artisan test --compact                     # every suite
docker compose exec app php artisan test --compact --testsuite=Browser
docker compose exec app php artisan test --compact tests/Integration   # real S3 + concurrency
```

| Suite | What it covers |
| - | - |
| `tests/Unit` | Decibel arithmetic, including the spec §11 regression values |
| `tests/Feature` | Device API contract (incl. OpenAPI fixtures), ingestion and idempotency, rollups, DST and charts, events and snapshots, recordings, configuration and heartbeat, authentication, exports, retention, panel access and cross-account isolation. Uses MySQL with `RefreshDatabase`. |
| `tests/Integration` | Concurrent identical/changed batches (`pcntl_fork`, committed data), plus real RustFS presigned PUT, finalization, hashing and Range download |
| `tests/Browser` | Pest 4 + Playwright: onboarding, event review, audio playback, export download |

Tests use the `noise_monitor_test` database and the `noise-monitor-test` bucket (see `phpunit.xml`). The isolated databases `noise_monitor_test_a`, `_b` and `_c` (created by `docker/mysql-init`) let parallel runs avoid each other:

```bash
docker compose exec -T -e DB_DATABASE=noise_monitor_test_b app php artisan test --compact tests/Feature/Exports
```

## Acceptance walkthrough (spec §20)

1. **Provision.** Sign in as an owner, then create a property and a device. On the device, record a placement, publish a configuration (optional; the Pi otherwise runs on local defaults), and issue a credential. The Pi registers its own measurement profile and calibration. Or run `noise:demo:provision`.
2. **Replay a synthetic day.** Run `noise:simulate --token=… --scenario=day --speed=0`. The dashboard shows coverage, freshness and charts once the `queue-default` worker has rebuilt rollups.
3. **Review.** Open **Events**, choose an event with a *Verified* recording, select **Load player**, and inspect the chart, agent vs server summaries, and provenance.
4. **Annotate.** Click **Review**, set a status, source label, observed/suspected and confidence, and add notes. This is stored as an append-only annotation.
5. **Export.** Choose **More → Export this event** (or **Reports → New report**). When the `queue-exports` worker is done, download the ZIP and check `manifest.json`.
6. **Retention.** Mark the event **Keep**, then run `php artisan noise:retention --dry-run` and `noise:retention`. Kept evidence is preserved.
7. **Isolation.** Create a second owner with `noise:create-owner other@example.com`. That user cannot open the first account's URLs (404). This is covered by `tests/Feature/Panel/PanelPagesTest.php`.

## Documentation

| Document | Contents |
| - | - |
| [docs/openapi/device-api-v1.yaml](docs/openapi/device-api-v1.yaml) | Device API contract (OpenAPI 3.1); fixtures in [docs/openapi/fixtures](docs/openapi/fixtures/README.md) |
| [docs/architecture/0001-calibration-and-provenance.md](docs/architecture/0001-calibration-and-provenance.md) | Calibration states and provenance model |
| [docs/architecture/0002-idempotency-and-hashing.md](docs/architecture/0002-idempotency-and-hashing.md) | Canonical hashing and replay rules |
| [docs/architecture/0003-aggregation-and-quality-policy.md](docs/architecture/0003-aggregation-and-quality-policy.md) | Energy aggregation, coverage, quality policy, DST |
| [docs/architecture/0004-audio-finalization.md](docs/architecture/0004-audio-finalization.md) | Recording upload, verification and finalization |
| [docs/architecture/0005-local-s3-rustfs.md](docs/architecture/0005-local-s3-rustfs.md) | Local S3 (RustFS) and URL-signing endpoints |
| [docs/deployment-laravel-cloud.md](docs/deployment-laravel-cloud.md) | Deploying to Laravel Cloud |
| [docs/runbook.md](docs/runbook.md) | Operations, backups, restore, rotation, incidents |
| [docs/simulator.md](docs/simulator.md) | Simulator and demo provisioning |
| [docs/benchmarks.md](docs/benchmarks.md) | Measured performance results |
| [docs/limitations.md](docs/limitations.md) | Known limitations and deviations |

## Security notes

- Device credentials are bearer tokens prefixed `nmd_`. Only a SHA-256 digest is stored, and each token is bound to one device and valid only on `/api/v1/device`.
- Credentials, presigned-URL signatures and invitation tokens are redacted from logs (`app/Logging/RedactSensitiveData.php`).
- The values in `.env.example` work only against the local compose services. Never reuse them anywhere else.

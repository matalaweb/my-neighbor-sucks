# Simulator and demo provisioning

The simulator stands in for the Raspberry Pi capture agent. It talks **only** to the public device API over HTTP and never touches the database, so it exercises the same contract a real agent uses ([OpenAPI](openapi/device-api-v1.yaml)).

> **Synthetic data.** The acoustic patterns (background, vehicle-like burst, garage-door-like noise) are generated. They test the pipeline and say nothing about source classification. Demo accounts, properties and devices are labelled "synthetic", and the simulator prints a warning on every run.

## 1. Provision a demo device (development only)

```bash
docker compose exec app php artisan noise:demo:provision
docker compose exec app php artisan noise:demo:provision --with-uncalibrated   # adds a dBFS-only mic-2 channel
```

| Option | Default | Meaning |
| - | - | - |
| `--account=` | `Demo (synthetic)` | Account to create or reuse. A name without "demo" gets " — demo (synthetic)" appended. |
| `--email=` | `demo-owner@example.test` | Owner login, created if missing. The password is printed once. |
| `--with-uncalibrated` | off | Also provision an uncalibrated `mic-2` channel that reports only dBFS |
| `--force` | off | Allow running in production. Don't, except in a throwaway environment. |

Each run creates a **new** device ("Simulated Pi XXXX") with a placement, a calibrated measurement profile, a calibration record and a published configuration. It prints a device token **once**.

## 2. Run scenarios

```bash
docker compose exec app php artisan noise:simulate --token=nmd_... --scenario=burst
docker compose exec app php artisan noise:simulate --token=nmd_... --scenario=all --speed=0
docker compose exec app php artisan noise:simulate --token=nmd_... --scenario=day --date=2026-10-06 --hours=24 --speed=0
```

| Option | Default | Meaning |
| - | - | - |
| `--token=` | `NOISE_SIM_TOKEN` env | Device bearer token |
| `--base-url=` | `http://localhost:8080` | App base URL (inside the app container the server listens on 8080) |
| `--scenario=*` | `background` | Repeatable; `all` runs every scenario except `day` |
| `--date=` | yesterday (UTC) | Day to replay for `day`; must be in the past |
| `--hours=` | `24` | Hours to replay for `day` (maximum 24) |
| `--seed=` | `42` | Random seed |
| `--speed=` | `1` | Real-time divisor for simulated delays (`0` = don't wait) |
| `--no-verify-wait` | off | Don't poll recordings until they are verified |

The simulator first fetches `GET /configuration` to learn its provenance IDs and sends a heartbeat. It then lays the scenarios out back-to-back on one timeline ending now and prints a summary of requests, inserted and duplicate readings, rejections by error code, and per-scenario checks. It follows the agent retry policy: exponential backoff with jitter on network errors and 5xx, honouring `Retry-After` on 429, and always reusing the same identities.

| Scenario | What it demonstrates |
| - | - |
| `background` | 5 minutes of quiet one-second readings |
| `burst` | A vehicle-like burst: open event revision, then finalized revision, then a synthetic WAV clip uploaded, completed and polled until verified |
| `garage` | Garage-door-like noise with a recording, for practising "household/garage" review labels |
| `missing-audio` | An event that expects a recording that never arrives (shown as pending, then missing after 72 h) |
| `clipping` | A burst whose readings carry the `clipping` flag (excluded from summaries) |
| `uncalibrated` | With `--with-uncalibrated`: dBFS-only readings on `mic-2`. Without it: an unknown calibration reference, rejected with `unknown_provenance` |
| `offline-replay` | 30 minutes of locally queued batches replayed out of order after a lost acknowledgment; rollups are repaired without double counting |
| `duplicates` | The exact same batch re-sent (200, `replayed: true`), then the same batch ID with a changed payload (409 `batch_conflict`) |
| `clock` | Readings flagged `unsynchronized_clock` plus an unsynchronized heartbeat (offset 7,350 ms), then a batch 6 minutes in the future rejected with `clock_future_timestamp` |
| `delayed-event` | Readings arrive first; the event revision is posted after them, and the snapshot is supplemented |
| `delayed-recording` | The recording is declared and uploaded after a pause; it attaches to the right event later |
| `day` | A full replay of a past UTC day (86,400 readings in 300-record batches), with synthetic disturbances about every 45 minutes during the day; every other event gets a recording |

Recording verification needs the `queue-recordings` worker and rollups need `queue-default`. `docker compose up -d` starts both.

## Caveat: re-running on the same device

Each simulator run uses a new boot ID. Scenarios end "now", so running the simulator again soon afterwards on the **same device** (or replaying the same `--date` twice) produces two boots reporting the same seconds on one channel. Those intervals are correctly flagged as **ambiguous overlaps** and excluded from official summaries. For clean demos, provision a fresh device per run (`noise:demo:provision` creates a new device each time) or replay a different date.

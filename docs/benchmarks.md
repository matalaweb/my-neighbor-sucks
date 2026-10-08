# Benchmarks (spec §18)

Measured 2026-10-08 with `php artisan noise:benchmark` against an isolated
database (`noise_monitor_bench`). These are results from one local
environment, not guarantees for production; re-run on Laravel Cloud before
relying on them.

```sh
docker compose exec mysql mysql -uroot -proot-local-only -e "CREATE DATABASE IF NOT EXISTS noise_monitor_bench; GRANT ALL ON noise_monitor_bench.* TO 'noise'@'%';"
docker compose exec -e DB_DATABASE=noise_monitor_bench app php artisan migrate:fresh --force
docker compose exec -e DB_DATABASE=noise_monitor_bench -e QUEUE_CONNECTION=null app php -d memory_limit=1G artisan noise:benchmark
```

## Environment

| Item | Value |
| - | - |
| Host | Apple Silicon Mac, Docker Desktop, 12 vCPU / 16 GB to the VM (aarch64 Linux containers) |
| PHP / Laravel | 8.4.26 / 13.35.0, OPcache off for CLI |
| MySQL | 8.4.11 (compose service, default settings) |
| Method | Requests go through the full Laravel HTTP kernel in process: middleware, auth, rate limiter, validation, and transaction. No client network latency. |

## Dataset

- Ten devices, each with a placement, a calibrated profile, a calibration record, and a configuration.
- 30 days of one-second history on the primary device: **2,592,000 raw rows** and 43,200 minute rollups.
- The history is bulk-inserted with SQL. It is a benchmark dataset, not simulator traffic, and was seeded in about 100 s.
- Rollups for the seeded history use set-based SQL with the same energy formula. New data goes through the normal rebuilder.

## Results

| Target | Measured | Result |
| - | - | - |
| Ingest a 300-record batch in under 1 s at p95 | p50 75.9 ms, **p95 81.7 ms**, max 116.5 ms (n=40, with 2.6M rows present) | Pass |
| Sustain 10 devices at 1 reading/s in 30 s batches | 100 batches (300 simulated seconds) took 1.61 s of wall time, 187× headroom; p95 18.5 ms | Pass |
| Load the 24-hour dashboard in under 2 s at p95 with 30 days of raw data | Full page render: p50 52.4 ms, **p95 63.4 ms** (n=40); 1,440 points per series at 1-minute resolution | Pass |
| Show a received measurement within 15 s of its durable commit | Latest-reading tile reads raw rows, so it is current on the next poll (≤ 15 s). Minute/hour rebuild after a 30-record commit took 880 ms. | Pass (see note) |
| Recover a 24-hour backlog with no duplicates | 288 batches, 86,400 rows; every 10th batch was resent to mimic lost acknowledgments. 86,400 rows stored, **0 duplicates**. Server time 23.6 s; p95 93.9 ms per batch. | Pass |
| Keep chart responses bounded | ≤ 2,000 points per series, enforced by `MeasurementSeries::chooseResolution` (tests in `tests/Feature/Measurements/TimezoneAndChartTest.php`) | Pass |
| Survive a worker outage | Ingestion commits rows plus durable `maintenance_jobs` records and needs no worker. The reconciler re-dispatches the records when workers return (`tests/Feature/Measurements/RollupTest.php`). | Pass (by design and tests) |

Notes:

- **Rate limits.** Latency was measured with the per-device limits lifted, so the 40 back-to-back batches were not throttled. Under the configured 60 batches/min (burst 10), a 288-batch backlog takes at least 4.8 minutes to replay. The agent should honour `Retry-After`. Current readings from other devices are unaffected because limits are per device.
- **Chart latency.** Rollups are rebuilt by the `rollups` worker, which is woken after each commit. The reconciler re-dispatches anything left behind every 60 s. The 15 s figure depends on that worker running.

## Bottleneck found and fixed

The first run measured a dashboard **p95 of 2,982 ms**, which missed the target. The "latest reading" query ordered all of a device's rows by `captured_at` without constraining `channel`. MySQL therefore could not walk the `(account_id, device_id, channel, captured_at)` index and filesorted 2.6M rows.

`DashboardSummary` now runs one index-ordered lookup per channel. The summary query went from 2,314 ms to 3.9 ms, and the page p95 to 63 ms.

## Not measured here

- Production hardware, Laravel Cloud networking, and R2 latency.
- PHP-FPM/FrankenPHP worker concurrency under parallel load. All measurements were sequential, in a single process.
- Export build time for very large bundles, which is bounded by object-storage throughput.

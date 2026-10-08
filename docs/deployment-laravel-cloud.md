# Deploying to Laravel Cloud

Laravel Cloud is the intended production host. It builds from the Git repository. The Dockerfile and `compose.yaml` in this repository are for local development and CI only.

Use the Cloud CLI (`composer require --dev laravel/cloud-cli`, then `./vendor/bin/cloud auth -n`) or the Cloud console. Discover command options with `cloud <command> -h`; never guess them. Destructive Cloud operations (deleting environments, databases, buckets) must be confirmed explicitly.

## 1. Application and environment

| Setting | Value |
| - | - |
| PHP | 8.4 |
| Region | Same region for compute, database, cache and bucket |
| Build command | `composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan optimize && php artisan filament:optimize` |
| Deploy command | `php artisan migrate --force` |

- Run caching and optimization during the build. Deploy commands run just before the release goes live, and their filesystem changes are not kept.
- Do not add `queue:restart`, `optimize:clear` or `storage:link` to the deploy command. Cloud restarts workers itself.
- `composer install` runs `php artisan filament:upgrade` (post-autoload-dump), which publishes Filament's assets into `public/`.

## 2. Resources

| Resource | Notes |
| - | - |
| **Database** | Laravel MySQL 8. Connection variables are injected. The app sets the MySQL session time zone to `+00:00` (`config/database.php`). |
| **Cache / KV** | Laravel Valkey (Redis-compatible). `REDIS_*` variables are injected. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`. |
| **Object storage** | One **private** bucket, attached as the environment's **default disk**. The app writes recordings, exports and attachments to `config('noise.storage.disk')`, which falls back to `FILESYSTEM_DISK` (`config/noise.php`). After deploying, confirm with `php artisan config:show noise.storage.disk` that this resolves to the bucket's disk. |

Object storage notes (Cloudflare R2 underneath):

- R2 applies visibility per bucket, not per object. Do not set per-object ACLs or `visibility: public`; nothing in this app needs public objects.
- R2 has no object versioning, and the app doesn't need it. Verification streams the staging object once, hashes those exact bytes, and writes them to a new server-owned key per upload attempt (see [ADR 0004](architecture/0004-audio-finalization.md)).
- Leave `AWS_BROWSER_ENDPOINT` and `AWS_DEVICE_ENDPOINT` **empty**. They exist only so the local Docker stack can sign URLs for different hostnames. In production every client reaches the same bucket endpoint.
- Optionally add a lifecycle rule on the `staging/` prefix only (for example, expire after 2 days) as a backstop. **Never** add lifecycle rules on `recordings/`, `exports/` or `attachments/`. Retention and keep flags are enforced by the application.
- Calibration and placement attachments go through Livewire temporary uploads on the **local** disk (`LIVEWIRE_TMP_DISK=local`, `config/livewire.php`). This assumes the upload and the form submission land on the same replica. Run the web app on one replica, or set `LIVEWIRE_TMP_DISK` to the bucket disk and test the browser upload path.

## 3. Queue workers and scheduler

Create background processes (or a worker cluster) that mirror `compose.yaml`. Keep the three groups separate so a large export can't delay new data:

| Process | Command |
| - | - |
| Recordings (verification, media) | `php artisan queue:work redis --queue=recordings --sleep=1 --tries=5 --backoff=10 --timeout=900 --memory=512 --max-time=3600` |
| Exports (PDF/CSV/ZIP) | `php artisan queue:work redis --queue=exports --sleep=1 --tries=3 --backoff=30 --timeout=900 --max-time=3600` |
| Rollups and default | `php artisan queue:work redis --queue=rollups,default --sleep=1 --tries=5 --backoff=5 --timeout=150 --max-time=3600` |

Enable the **scheduler** on the app or worker cluster. Every scheduled task in `routes/console.php` uses `onOneServer()`:

| Schedule | Command |
| - | - |
| Every minute | `noise:reconcile` |
| Hourly at :07 | `noise:reconcile --deep` |
| Daily 03:30 | `noise:retention` |
| Daily | `queue:prune-failed --hours=720` |

Worker sizing:

- Recording verification copies up to 100 MiB per segment through `sys_get_temp_dir()`. Give the recordings worker enough ephemeral disk and memory (PHP `memory_limit` 512M is set locally in `docker/php.ini`).
- Exports can take minutes for large bundles; the job timeout is 900 s.
- `REDIS_QUEUE_RETRY_AFTER` defaults to 960 s (config/queue.php) so a long verification or export is never re-delivered while still running. Keep it above the largest worker `--timeout`.
- Avoid scale-to-zero compute for workers. Long-running jobs can be interrupted when the instance sleeps.

## 4. Environment variables

| Variable | Production value |
| - | - |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-domain` |
| `APP_KEY` | Generated once and kept stable (encrypts property addresses; see the [runbook](runbook.md#app_key)) |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` |
| `NOISE_DEFAULT_TIMEZONE` | `America/Chicago` (default for new properties) |
| `LOG_CHANNEL` | `stderr` (suggested; the stack/single/daily/stderr channels all apply log redaction) |
| `LOG_LEVEL` | `info` or `warning` |
| `MAIL_*` | A real mailer. Invitations and password resets send email; passwords are never emailed. |
| `AWS_BROWSER_ENDPOINT`, `AWS_DEVICE_ENDPOINT` | Unset or empty |
| Error tracking | Optional: Laravel Nightwatch or Sentry. Not installed by default. |

Put secrets (mail credentials, error-tracking DSNs) in Secrets Manager and redeploy after any change to variables, attached resources or secrets.

## 5. TLS, proxies, health checks

- Cloud terminates TLS at its edge. `bootstrap/app.php` trusts forwarded headers from all proxies (`trustProxies(at: '*')`), and `AppServiceProvider` forces `https` URLs in production.
- Liveness: `GET /up` (Laravel's built-in health route).
- Readiness: `GET /health/ready` (`app/Http/Controllers/HealthController.php`). It checks the database, cache store and object storage, and reports the oldest pending outbox age and the failed outbox count. It returns `503` when the database, cache or storage is unavailable, and never prints secrets.

## 6. Verify before go-live

- [ ] `php artisan migrate --force` succeeded and `/health/ready` returns `200`.
- [ ] A device can `PUT` to a presigned upload URL from its real network. Run `noise:simulate --scenario=burst` from a machine outside Cloud with `--base-url=https://your-domain` and confirm the recording reaches **Verified**.
- [ ] Browser playback works and supports HTTP Range: seek inside a clip on an event page.
- [ ] The recordings worker can verify a large segment (up to 100 MiB) within its memory, disk and time limits.
- [ ] The scheduler runs: **Operational status** shows outbox work draining, and `noise:reconcile` appears in the scheduler logs.
- [ ] Exports build and download, and the download link expires after 5 minutes.
- [ ] Automated database backups are enabled (see the [runbook](runbook.md#backups)).

## 7. Initial owner and first device

There is no public registration. Create the first owner once:

```bash
cloud command:run <environment> --cmd='php artisan noise:create-owner you@example.com --account="Household" --property="Home" --timezone=America/Chicago --generate-password' -n
```

The generated password is printed once; change it under **Profile** after signing in. Then, in the app:

1. **Devices → New device.**
2. On the device, record a **Placement**, a **Measurement profile** and, if applicable, a **Calibration** record.
3. **Configuration → Publish new revision.**
4. **Credentials → Issue credential.** Copy the token, which is shown once, and the API base URL (`https://your-domain/api/v1/device`) into the Pi agent.

Never run `noise:demo:provision` or `noise:storage:ensure-buckets` in production. `noise:storage:ensure-buckets` always refuses there, and `noise:demo:provision` refuses unless `--force` is passed. Demo data must not appear in production accounts.

## 8. Rollback

- Redeploy the previous successful deployment from the Cloud console or with `cloud deploy`. Always follow a deploy with `cloud deploy:monitor -n`.
- The current migrations only create tables. If a future release adds a destructive migration (dropping or renaming a column or table), take a database backup first and make the release backward-compatible: expand in one release, contract in a later one. Rolling back the code does not undo a destructive migration.
- Device credentials, configuration revisions and stored evidence are unaffected by a code rollback.

## Current production deployment (2026-10-08)

Provisioned with the Cloud CLI on the smallest feasible footprint (single household, one Pi).

| Item | Value |
| - | - |
| Application | `my-neighbor-sucks` (`app-a2efaf33-a729-4d6f-9c31-8e2eff83f75a`), repo `matalaweb/my-neighbor-sucks`, branch `main`, push-to-deploy on |
| Environment | `production` (`env-a2efaf35-ae84-4643-964f-e740a6b95a99`), region us-east-2, PHP 8.5 (Cloud default), Node 26 |
| URL | https://my-neighbor-sucks-production-1z64fu.laravel.cloud |
| Compute | 1 × `flex.m-1vcpu-1gb`, min = max = 1 replica, scale-to-zero **off**, scheduler **on** |
| Background processes | three custom `queue:work redis` processes: `recordings` (timeout 900), `exports` (timeout 900), `rollups,default` (timeout 150) |
| Database | Laravel MySQL cluster `my-neighbor-sucks`, `mysql-flex-512mb`, 5 GB, automatic upsizing, daily backups kept 7 days; schema `production` |
| Cache / queues / sessions | Laravel Valkey `my_neighbor_sucks`, `valkey-flex-250mb`, eviction `volatile-lru` (queue lists have no TTL, so they are never evicted), auto-upgrade on |
| Object storage | private R2 bucket `my-neighbor-sucks-evidence` |

Why the storage variables are set by hand: the CLI can't attach a bucket as the environment's default disk. Instead, a read/write bucket key `app-runtime` was created and these were set manually:

- `FILESYSTEM_DISK=s3`
- `AWS_BUCKET=fls-a2efaf64-2b8a-4a17-9279-576da883150a` (the R2 bucket name is the Cloud bucket **ID**, not its display name)
- `AWS_ENDPOINT=https://367be3a2035528943240074d0096e0cd.r2.cloudflarestorage.com`
- `AWS_DEFAULT_REGION=auto`
- `AWS_USE_PATH_STYLE_ENDPOINT=true`
- `AWS_ACCESS_KEY_ID` as an environment variable, and `AWS_SECRET_ACCESS_KEY` as an encrypted organization secret attached to the environment

A bucket key's secret is shown only once, at creation. To rotate it:

1. Create a new key with `cloud bucket-key:create … --json --show-sensitive`.
2. Update the secret and `AWS_ACCESS_KEY_ID`.
3. Redeploy.
4. Delete the old key.

The key named `app` was created alongside the bucket. Its secret was never returned, so it is unused and can be deleted.

Other environment settings:

- `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`
- `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` set to `redis`
- `MAIL_MAILER=log`. Invitations and password-reset mail are not delivered until a mail provider such as Resend is attached.

Verified after the first deploy:

- `/up` and `/health/ready` return 200, with database, cache and object storage all OK.
- Secure session cookies are set.
- Queued jobs drain within seconds.
- The scheduler mutex appears every minute.
- Presigned PUT, GET and DELETE against the bucket succeed.

Estimated cost is about $18–20/month (compute about $12, MySQL about $5–6, Valkey about $0.40–2, storage usage-based).

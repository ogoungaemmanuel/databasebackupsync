# Deployment Guide

This guide covers running `database-backup-sync` in production: scheduling, queued execution, multi-server setups, monitoring, and hardening.

---

## 1. Deployment checklist

- [ ] `composer require xslain/database-backup-sync` (and `aws/aws-sdk-php` if using S3)
- [ ] Config published: `php artisan vendor:publish --tag=database-backup-config`
- [ ] Migration published & run: `php artisan vendor:publish --tag=database-backup-migrations && php artisan migrate`
- [ ] `.env` has the driver credentials, encryption key, and retention settings
- [ ] `php artisan db:backup:test` passes for every driver
- [ ] Scheduler cron installed (below)
- [ ] Queue worker running (if using `--queue`)
- [ ] Status endpoint enabled + token set (optional but recommended)
- [ ] Notifications configured (Slack/email/webhook) so failures page someone
- [ ] A restore drill has been performed at least once (see [Usage → Restore](USAGE.md#dbbackuprestore))

---

## 2. Scheduling

The package registers `db:backup` with Laravel's scheduler automatically (when `DB_BACKUP_SCHEDULE=true`, the default). You only need to make sure Laravel's scheduler itself runs every minute.

### Linux / macOS

Add to the web server user's crontab (`crontab -e`):

```cron
* * * * * cd /var/www/your-app && php artisan schedule:run >> /dev/null 2>&1
```

### Windows (Task Scheduler)

1. Open **Task Scheduler** → **Create Task**.
2. **General**: run as the service account; "Run whether user is logged on or not".
3. **Triggers**: repeat every 1 minute, indefinitely.
4. **Actions**: start a program:

   ```
   Program: C:\path\to\php.exe
   Arguments: artisan schedule:run
   Start in: C:\path\to\your-app
   ```

5. **Settings**: uncheck "Stop the task if it runs longer than 3 days".

### Schedule defaults

| Setting | Default | Env var |
| --- | --- | --- |
| Expression | `0 2 * * *` (daily 02:00) | `DB_BACKUP_SCHEDULE_EXPRESSION` |
| Timezone | `app.timezone` | `DB_BACKUP_SCHEDULE_TIMEZONE` |
| onOneServer | on | `DB_BACKUP_SCHEDULE_ONE_SERVER` |
| withoutOverlapping | on | `DB_BACKUP_SCHEDULE_NO_OVERLAP` |
| Output log | `storage/logs/database-backup-schedule.log` | `DB_BACKUP_SCHEDULE_LOG` |

> `onOneServer` and `withoutOverlapping` require a **shared cache** (`redis`, `database`, `memcached`). With `array`/`file` caches they are silently disabled — on multi-server setups that means every server would run the backup.

### Custom schedule

Disable the built-in registration and add your own in `routes/console.php`:

```dotenv
DB_BACKUP_SCHEDULE=false
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('db:backup', ['--driver=s3', '--encrypt'])
    ->dailyAt('02:00')
    ->timezone('Africa/Lagos')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/database-backup-schedule.log'));

Schedule::command('db:backup:prune')
    ->dailyAt('03:00')
    ->onOneServer();
```

---

## 3. Queued execution

For large databases, run the pipeline as a queued job so the web/CLI process returns immediately:

```bash
php artisan db:backup --queue
```

Requirements:

- A queue worker on the `backups` queue (or your custom `DB_BACKUP_QUEUE`):

  ```bash
  php artisan queue:work --queue=backups --tries=5 --timeout=3600
  ```

- Supervisor config (Linux) example:

  ```ini
  [program:backup-worker]
  process_name=%(program_name)s_%(process_num)02d
  command=php /var/www/your-app/artisan queue:work --queue=backups --tries=5 --timeout=3600
  autostart=true
  autorestart=true
  numprocs=1
  ```

- Job behavior:
  - `RunBackupJob` — the full pipeline, retried with exponential backoff `[10, 30, 60, 120, 300]` seconds, up to `DB_BACKUP_QUEUE_TRIES` (5) attempts.
  - Uploads are dispatched as **independent jobs** (`UploadBackupJob`) — a failure on one driver does not lose the whole backup.
  - `PruneBackupsJob` — retention pruning as a job.

---

## 4. Multi-server (load-balanced) setups

- Set `DB_BACKUP_SCHEDULE_ONE_SERVER=true` (default) with a shared cache so only one node runs the backup.
- Point `DB_BACKUP_LOCAL_ROOT` at a shared volume if you use the `local` driver, or prefer a cloud driver.
- The status endpoint is safe to expose on any node — it reads shared state (DB history + driver storage).

---

## 5. Monitoring

### Status endpoint

```dotenv
DB_BACKUP_STATUS_ENABLED=true
DB_BACKUP_STATUS_TOKEN=$(openssl rand -hex 32)
```

```bash
curl -s -H "X-Backup-Token: $DB_BACKUP_STATUS_TOKEN" \
  https://your-app.com/database-backup/status | jq '.last_run'
```

### Alerting

- **Notifications** — Slack/email/webhook on failure (see [Usage → Notifications](USAGE.md#notifications)). `DB_BACKUP_NOTIFY_FAILURE=true` is the default — keep it on.
- **Storage usage alerts** — `StorageUsageAlert` fires when a driver exceeds `DB_BACKUP_ALERT_THRESHOLD_BYTES` or `DB_BACKUP_ALERT_PERCENT` of `DB_BACKUP_QUOTA_BYTES`.
- **Logs** — per-driver upload failures are logged to `storage/logs/laravel.log` with the `database-backup:` prefix; scheduled output goes to `DB_BACKUP_SCHEDULE_LOG`.

### External monitoring

Probe the status endpoint from UptimeRobot / Datadog / your own cron:

```bash
# Fail if the last successful run is older than 26 hours
curl -s -H "X-Backup-Token: $TOKEN" https://your-app.com/database-backup/status \
  | jq -e '(.last_run.status == "completed") and ((now - (.last_run.finished_at | fromdateiso8601)) < 93600)'
```

---

## 6. Restore drills

Backups you have never restored are not backups. Schedule a quarterly drill:

```bash
# 1. List what's available
php artisan db:backup:list --driver=s3

# 2. Restore into a scratch connection (NOT production)
php artisan db:backup:restore backup_2026-08-16_02-00-00.sql.gz.enc \
    --driver=s3 --connection=scratch --decrypt

# 3. Verify row counts / schema, then drop the scratch database
```

---

## 7. Upgrades

```bash
composer update xslain/database-backup-sync
php artisan config:clear
php artisan view:clear
```

- Re-publish config only if you want the new defaults: `php artisan vendor:publish --tag=database-backup-config --force` (review the diff first — your `.env` values win anyway).
- New migrations are published with `--tag=database-backup-migrations` and run with `php artisan migrate`.
- After any credential change, re-run `php artisan db:backup:test`.

---

## 8. Common production pitfalls

| Pitfall | Fix |
| --- | --- |
| Scheduler cron missing → no backups | Install the `schedule:run` cron (section 2) |
| `onOneServer` silently disabled | Use `redis`/`database`/`memcached` cache |
| Dump binary not on `PATH` in cron environment | Set `DB_BACKUP_DUMP_BINARY` to an absolute path |
| Queue jobs never run | Start a worker on the `backups` queue |
| Secrets committed to `config/database-backup.php` | Move to `.env` / secret manager |
| Backup succeeds but uploads fail silently | Check `laravel.log` for `database-backup:` entries; enable `DB_BACKUP_NOTIFY_FAILURE` |
| Temp files accumulate | `DB_BACKUP_TEMP_PATH` is cleaned per run; ensure the web user can write to it |

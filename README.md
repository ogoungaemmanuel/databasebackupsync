# Database Backup Sync

Automated 24-hour database backups with multi-cloud upload (S3, Google Drive, OneDrive, local), AES-256-GCM / GPG encryption, retention policies, integrity verification, and observability for Laravel.

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.1-blue)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-8.83%20%7C%209%20%7C%2010%20%7C%2011%20%7C%2012%20%7C%2013-red)](composer.json)

---

## Features

- **Multi-database support** — MySQL, PostgreSQL, SQLite, SQL Server.
- **Native binary dumpers** (`mysqldump`, `pg_dump`, `sqlite3`, `sqlcmd`) with a **PDO streaming fallback** for hosts without shell access.
- **Multi-cloud upload** — S3 (multipart + resumable), Google Drive, OneDrive, and local disk. Uploads to each driver are independent — one failing driver never loses the backup.
- **Encryption at rest** — streaming **AES-256-GCM** (authenticated) built in, optional **GPG** (symmetric or recipient).
- **Retention policies** — prune by age, count, or total size (policies are ANDed).
- **Integrity verification** — SHA-256 checksums and a JSON manifest stored alongside every backup.
- **Scheduling** — registers `db:backup` with Laravel's scheduler (default: daily at 02:00), with `onOneServer` and `withoutOverlapping` support.
- **Queued execution** — run the whole pipeline (or just uploads) as queued jobs with exponential backoff.
- **Notifications** — Slack webhook, email, and generic webhook (HMAC-SHA256 signed) on success/failure.
- **Observability** — run history table, in-memory metrics, and an optional token-protected JSON status endpoint.
- **Restore** — download, decrypt, and restore a backup into any configured connection.

## Requirements

- PHP **^8.1**
- Laravel **8.83 – 13** (illuminate components)
- Extensions: `json`, `openssl`, `pdo`
- `guzzlehttp/guzzle` (bundled dependency)
- **Optional:** `aws/aws-sdk-php` (S3 driver), `ext-gnupg` or the `gpg` binary (GPG encryption)

## Installation

```bash
composer require xslain/database-backup-sync
```

Publish the configuration (and optionally the migration for run history):

```bash
php artisan vendor:publish --tag=database-backup-config
php artisan vendor:publish --tag=database-backup-migrations
php artisan migrate
```

> The migration is optional — run history and the status endpoint's `recent_runs` only work when the `database_backup_runs` table exists.

### Quick start

Set the minimum environment variables, then run a backup:

```dotenv
DB_BACKUP_DRIVER=local
DB_BACKUP_ENCRYPT=true
DB_BACKUP_KEY=base64:your-32-byte-key-here
```

```bash
# Verify driver connectivity with a probe upload
php artisan db:backup:test

# Take a backup and upload it
php artisan db:backup

# List stored backups
php artisan db:backup:list
```

Generate an encryption key with:

```bash
php -r "echo base64_encode(random_bytes(32));"
```

## Commands

| Command | Description |
| --- | --- |
| `php artisan db:backup` | Dump → compress → encrypt → upload → record → prune |
| `php artisan db:backup:prune` | Apply the retention policy and delete expired backups |
| `php artisan db:backup:list` | List stored backups across drivers |
| `php artisan db:backup:restore {file}` | Download, decrypt, and restore a backup |
| `php artisan db:backup:test` | Verify connectivity to each driver with a probe upload |

## Scheduling

The package registers `db:backup` with Laravel's scheduler automatically. Add the scheduler to your system cron (once per minute):

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Defaults (all configurable):

- Runs **daily at 02:00** (`0 2 * * *`)
- `onOneServer` — only one server runs the backup when using a shared cache
- `withoutOverlapping` — never run two backups at once

## Configuration

All configuration lives in `config/database-backup.php` (published) and is driven by environment variables. Highlights:

| Area | Env vars |
| --- | --- |
| Default driver | `DB_BACKUP_DRIVER` |
| S3 | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `DB_BACKUP_S3_BUCKET`, `DB_BACKUP_S3_PREFIX` |
| Google Drive | `DB_BACKUP_DRIVE_AUTH`, `DB_BACKUP_DRIVE_SERVICE_ACCOUNT_JSON`, `DB_BACKUP_DRIVE_FOLDER_ID` |
| OneDrive | `DB_BACKUP_ONEDRIVE_TENANT_ID`, `DB_BACKUP_ONEDRIVE_CLIENT_ID`, `DB_BACKUP_ONEDRIVE_CLIENT_SECRET`, `DB_BACKUP_ONEDRIVE_GRANT` |
| Encryption | `DB_BACKUP_ENCRYPT`, `DB_BACKUP_KEY`, `DB_BACKUP_GPG`, `DB_BACKUP_GPG_RECIPIENTS` |
| Retention | `DB_BACKUP_RETENTION_DAYS`, `DB_BACKUP_RETENTION_COUNT`, `DB_BACKUP_RETENTION_MAX_SIZE` |
| Scheduling | `DB_BACKUP_SCHEDULE_EXPRESSION`, `DB_BACKUP_SCHEDULE_TIMEZONE` |
| Notifications | `DB_BACKUP_SLACK_WEBHOOK_URL`, `DB_BACKUP_EMAIL_TO`, `DB_BACKUP_WEBHOOK_URL`, `DB_BACKUP_WEBHOOK_SECRET` |
| Queue | `DB_BACKUP_QUEUE`, `DB_BACKUP_QUEUE_TRIES` |
| Status endpoint | `DB_BACKUP_STATUS_ENABLED`, `DB_BACKUP_STATUS_TOKEN` |

See **[docs/CONFIGURATION.md](docs/CONFIGURATION.md)** for the complete reference.

## Programmatic usage

```php
use DatabaseBackupSync\Facades\DatabaseBackup;

// Run a backup to the default driver(s)
$result = DatabaseBackup::backup(['label' => 'pre-deploy']);

// Backup to specific drivers, encrypted
$result = DatabaseBackup::backup([
    'drivers' => ['s3', 'google_drive'],
    'encrypt' => true,
    'label'   => 'nightly',
]);

// Apply retention pruning
DatabaseBackup::prune();

// List backups
$backups = DatabaseBackup::listBackups('s3');

// Restore a backup
DatabaseBackup::restore('backup_2026-08-16_02-00-00.sql.gz.enc', [
    'driver'     => 's3',
    'connection' => 'mysql',
    'decrypt'    => true,
]);

// Test driver connectivity
DatabaseBackup::testDrivers();
```

## Status endpoint

When enabled, a token-protected JSON endpoint reports the last run, recent runs, storage usage, and metrics:

```dotenv
DB_BACKUP_STATUS_ENABLED=true
DB_BACKUP_STATUS_TOKEN=your-secret-token
```

```bash
curl -H "X-Backup-Token: your-secret-token" https://your-app.com/database-backup/status
```

## Events

Listen to these events to build your own integrations:

| Event | Fired when |
| --- | --- |
| `BackupStarted` | A backup run begins |
| `BackupCompleted` | A backup finished and was uploaded |
| `BackupFailed` | A backup run failed |
| `BackupUploaded` | A file was uploaded to one driver |
| `BackupUploadFailed` | An upload to one driver failed |
| `BackupPruned` | Retention pruning deleted files |
| `StorageUsageAlert` | A driver's usage exceeded the configured threshold |

## Documentation

- [Installation & packages](docs/INSTALLATION.md)
- [Configuration reference](docs/CONFIGURATION.md)
- [Drivers (S3, Google Drive, OneDrive, local)](docs/DRIVERS.md)
- [Usage: commands & API](docs/USAGE.md)
- [Deployment guide](docs/DEPLOYMENT.md)
- [Security & encryption](docs/SECURITY.md)

## Testing

```bash
composer test            # full suite
composer test:unit       # unit tests only
composer test:integration
composer test:feature
composer lint            # PHP lint on the Manager
```

## License

MIT — see [LICENSE](LICENSE).

Built by [Xslain Innovations Limited](https://www.xslain.com).

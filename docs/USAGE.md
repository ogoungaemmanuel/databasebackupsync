# Usage

## Commands

### `db:backup`

Dump → compress → encrypt → upload → record → prune.

```bash
php artisan db:backup
```

| Option | Description |
| --- | --- |
| `--driver=*` | Cloud driver(s) to upload to. Repeatable: `--driver=s3 --driver=onedrive` |
| `--connection=` | Database connection to back up (defaults to the app default) |
| `--encrypt` / `--no-encrypt` | Force encryption on/off (overrides config) |
| `--queue` | Dispatch the backup as a queued job |
| `--streaming` | Force the PDO streaming dumper instead of the native binary |
| `--gzip` / `--no-gzip` | Force gzip on/off |
| `--prune` / `--no-prune` | Force retention pruning on/off after the backup |
| `--label=` | Label for this run (default: `scheduled`; manual runs default to `manual`) |
| `--filename=` | Override the remote filename |

Examples:

```bash
# Nightly to S3 + Google Drive, encrypted, with a label
php artisan db:backup --driver=s3 --driver=google_drive --encrypt --label=nightly

# Backup a specific connection using the streaming dumper
php artisan db:backup --connection=analytics --streaming

# Skip pruning this run
php artisan db:backup --no-prune

# Run as a queued job
php artisan db:backup --queue
```

### `db:backup:prune`

Apply the retention policy and delete expired backups.

```bash
php artisan db:backup:prune
php artisan db:backup:prune --driver=s3
php artisan db:backup:prune --queue
```

### `db:backup:list`

List stored backups across drivers (or one driver).

```bash
php artisan db:backup:list
php artisan db:backup:list --driver=s3
```

### `db:backup:restore`

Download, decrypt, and restore a backup into the database.

```bash
php artisan db:backup:restore backup_2026-08-16_02-00-00.sql.gz.enc
```

| Argument / Option | Description |
| --- | --- |
| `file` | Remote backup filename to restore (required) |
| `--driver=` | Driver to download from (defaults to the default driver) |
| `--connection=` | Database connection to restore into |
| `--decrypt` / `--no-decrypt` | Force decryption on/off |

> ⚠️ **Restoring overwrites data in the target database and is not reversible.** The command asks for confirmation before proceeding.

```bash
php artisan db:backup:restore backup_2026-08-16_02-00-00.sql.gz.enc \
    --driver=s3 --connection=mysql --decrypt
```

### `db:backup:test`

Verify connectivity to each driver with a probe upload (see [Drivers → Testing](DRIVERS.md#testing-drivers)).

```bash
php artisan db:backup:test
php artisan db:backup:test --driver=s3
```

---

## Programmatic API

The `DatabaseBackup` facade proxies the `Manager` class. Resolve the manager directly for dependency injection:

```php
use DatabaseBackupSync\Manager;

class SomeService
{
    public function __construct(private Manager $backups) {}
}
```

### `backup(array $options = []): BackupResult`

```php
use DatabaseBackupSync\Facades\DatabaseBackup;

$result = DatabaseBackup::backup([
    'drivers'    => ['s3', 'google_drive'],
    'connection' => 'mysql',
    'encrypt'    => true,
    'gzip'       => true,
    'label'      => 'pre-deploy',
    'filename'   => 'custom-name.sql.gz.enc',
    'prune'      => true,
    'streaming'  => false,
]);

$result->file;        // remote filename
$result->size;        // bytes
$result->checksum;    // SHA-256
$result->encrypted;   // bool
$result->connection;  // connection name
$result->durationMs;  // elapsed ms
$result->runId;       // database_backup_runs.id (null if history disabled)
$result->uploads;     // UploadResult[] — one per driver
```

### `prune(?array $drivers = null): PruneResult`

```php
$result = DatabaseBackup::prune(['s3', 'local']);

$result->prunedCount();  // number of files deleted
$result->prunedBytes();  // bytes freed
$result->pruned;         // [['driver' => ..., 'path' => ..., 'size' => ...], ...]
$result->errors;         // [driver => error message, ...]
```

### `listBackups(?string $driver = null): array`

```php
$backups = DatabaseBackup::listBackups('s3');
// ['s3' => [RemoteFile, RemoteFile, ...]]

foreach ($backups['s3'] as $file) {
    $file->path;         // remote path
    $file->size;         // bytes
    $file->lastModified; // unix timestamp or null
    $file->checksum;     // SHA-256 or null
}
```

### `restore(string $remotePath, array $options = []): RestoreResult`

```php
$result = DatabaseBackup::restore('backup_2026-08-16_02-00-00.sql.gz.enc', [
    'driver'     => 's3',
    'connection' => 'mysql',
    'decrypt'    => true,
]);

$result->remotePath;
$result->driver;
$result->connection;
```

### `testDrivers(?array $drivers = null): array`

```php
$results = DatabaseBackup::testDrivers();
// ['s3' => ['ok' => true, 'error' => null], ...]
```

### `driver(string $name): DriverContract`

Resolve a driver instance directly (e.g. to call `list()`, `upload()`, `delete()` yourself).

### `metrics(): Metrics`

Access the in-memory metrics snapshot (counters and gauges for dumps, uploads, backups, pruning).

---

## Events

All events live in `DatabaseBackupSync\Events`. Register listeners in a service provider:

```php
use DatabaseBackupSync\Events\BackupCompleted;
use DatabaseBackupSync\Events\BackupFailed;

Event::listen(function (BackupCompleted $event) {
    // $event->result — BackupResult
    logger()->info('Backup completed', ['file' => $event->result->file]);
});

Event::listen(function (BackupFailed $event) {
    // $event->connection, $event->label, $event->exception
    // → page your on-call, open an incident, etc.
});
```

| Event | Payload |
| --- | --- |
| `BackupStarted` | `connection`, `label` |
| `BackupCompleted` | `result` (`BackupResult`) |
| `BackupFailed` | `connection`, `label`, `exception` |
| `BackupUploaded` | `driver`, `path`, `size`, `checksum`, `multipart` |
| `BackupUploadFailed` | `driver`, `file`, `exception` |
| `BackupPruned` | `driver`, `path`, `size` |
| `StorageUsageAlert` | driver usage details |

---

## Notifications

Notifications are wired automatically through `BackupNotificationListener`. Configure channels in `config/database-backup.php` → `notifications`:

- **Slack** — incoming webhook (`DB_BACKUP_SLACK_WEBHOOK_URL`)
- **Email** — Laravel mail (`DB_BACKUP_EMAIL_TO`)
- **Webhook** — generic HTTP POST with an HMAC-SHA256 signature in the `X-Backup-Signature` header when `DB_BACKUP_WEBHOOK_SECRET` is set

Gate success/failure notifications:

```dotenv
DB_BACKUP_NOTIFY_SUCCESS=true
DB_BACKUP_NOTIFY_FAILURE=true
```

---

## Status endpoint

Enable the JSON status endpoint for monitoring:

```dotenv
DB_BACKUP_STATUS_ENABLED=true
DB_BACKUP_STATUS_TOKEN=change-me
```

```bash
curl -H "X-Backup-Token: change-me" https://your-app.com/database-backup/status
# or: https://your-app.com/database-backup/status?token=change-me
```

Response includes:

- `last_run` and `recent_runs` (from `database_backup_runs`, when the migration is published)
- `storage_usage` per driver
- `metrics` snapshot
- `config` summary (default driver, encryption, retention, schedule)

The route is registered under the `api` middleware group plus `VerifyBackupToken`. Change the prefix with `DB_BACKUP_STATUS_PREFIX`.

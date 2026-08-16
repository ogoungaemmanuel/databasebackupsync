# Installation Guide

This guide covers installing `xslain/database-backup-sync` into a Laravel application, including the optional packages you may need depending on which drivers and features you use.

## 1. Requirements

| Requirement | Version / Notes |
| --- | --- |
| PHP | `^8.1` |
| Laravel | `8.83` – `13` (illuminate components) |
| PHP extensions | `json`, `openssl`, `pdo` |
| Composer | 2.x |

### Database dump binaries (recommended)

The package prefers native dump binaries for speed and fidelity. Install the one matching your database:

| Database | Binary | Notes |
| --- | --- | --- |
| MySQL / MariaDB | `mysqldump` | Ships with the MySQL/MariaDB client |
| PostgreSQL | `pg_dump` | Ships with the PostgreSQL client |
| SQLite | `sqlite3` | Ships with SQLite |
| SQL Server | `sqlcmd` | Ships with the SQL Server tools |

If the binary is missing, the package automatically falls back to a **PDO streaming dumper** (row-by-row `SELECT` + `INSERT`), so backups still work — just slower on large databases. You can force the streaming dumper with `--streaming` or `DB_BACKUP_STREAMING=true`.

## 2. Install the package

```bash
composer require xslain/database-backup-sync
```

Laravel auto-discovers the service provider (`DatabaseBackupSync\DatabaseBackupServiceProvider`) and the `DatabaseBackup` facade alias. If you have disabled package discovery, register them manually in `config/app.php`:

```php
'providers' => [
    // ...
    DatabaseBackupSync\DatabaseBackupServiceProvider::class,
],

'aliases' => [
    // ...
    'DatabaseBackup' => DatabaseBackupSync\Facades\DatabaseBackup::class,
],
```

## 3. Optional packages

Install these **only if you need the corresponding feature**:

### S3 driver — `aws/aws-sdk-php`

```bash
composer require aws/aws-sdk-php
```

Required for the `s3` driver (multipart + resumable uploads). Without it, `db:backup:test --driver=s3` fails with a "class not found" error.

### GPG encryption — `ext-gnupg` or the `gpg` binary

```bash
# Option A: PHP extension (fastest)
#   Windows: enable extension=gnupg in php.ini
#   Debian/Ubuntu: sudo apt install php-gnupg
#   macOS (Homebrew): brew install php-gnupg

# Option B: gpg binary (shell fallback)
#   Debian/Ubuntu: sudo apt install gnupg
#   macOS: brew install gnupg
#   Windows: install Gpg4win and add gpg.exe to PATH
```

The package uses `ext-gnupg` when available and falls back to the `gpg` binary. Set `DB_BACKUP_GPG_BINARY` if `gpg` is not on `PATH`.

## 4. Publish configuration

```bash
php artisan vendor:publish --tag=database-backup-config
```

This creates `config/database-backup.php`. The published file is fully commented — it is the best reference for every option.

## 5. Publish the migration (optional, recommended)

Run history (the `database_backup_runs` table) powers the status endpoint's `last_run` / `recent_runs` and gives you an audit trail of every backup.

```bash
php artisan vendor:publish --tag=database-backup-migrations
php artisan migrate
```

> If you skip this, backups still work — history recording is silently skipped when the table is absent.

## 6. Environment variables

Add the minimum configuration to your `.env`:

```dotenv
# Default driver: local | s3 | google_drive | onedrive
DB_BACKUP_DRIVER=local

# Encryption (recommended for any off-server storage)
DB_BACKUP_ENCRYPT=true
DB_BACKUP_KEY=base64:REPLACE_WITH_GENERATED_KEY

# Retention
DB_BACKUP_RETENTION_DAYS=14
DB_BACKUP_RETENTION_COUNT=30
```

Generate the encryption key:

```bash
php -r "echo base64_encode(random_bytes(32));"
```

See [docs/CONFIGURATION.md](CONFIGURATION.md) for the full environment variable reference, and [docs/DRIVERS.md](DRIVERS.md) for per-driver setup.

## 7. Verify the installation

```bash
# 1. Confirm the commands are registered
php artisan list | grep db:backup

# 2. Test driver connectivity (probe upload + delete)
php artisan db:backup:test

# 3. Take a real backup
php artisan db:backup

# 4. Confirm it landed
php artisan db:backup:list
```

Expected output of a successful run:

```
Backup completed in 3.2s: backup_2026-08-16_02-00-00.sql.gz (1.24 MB, encrypted)
+-------+------------------------------------------+---------+--------+--------------+
| Driver| File                                     | Size    | Mode   | SHA-256      |
+-------+------------------------------------------+---------+--------+--------------+
| local | backup_2026-08-16_02-00-00.sql.gz.enc    | 1.24 MB | single | 3f9a2c…      |
+-------+------------------------------------------+---------+--------+--------------+
```

## 8. Troubleshooting

| Symptom | Cause / Fix |
| --- | --- |
| `Driver [s3] not found` or S3 class errors | `aws/aws-sdk-php` is not installed — `composer require aws/aws-sdk-php` |
| `mysqldump: command not found` | Binary not on `PATH` — set `DB_BACKUP_DUMP_BINARY=/full/path/to/mysqldump` or use streaming |
| `Google Drive service account JSON key file not found` | `DB_BACKUP_DRIVE_SERVICE_ACCOUNT_JSON` must be an absolute path to the JSON key file |
| `Backup failed: No backup was uploaded to any driver` | Every driver failed — check `storage/logs/laravel.log` for per-driver errors |
| Backup runs but history is empty | The migration was not published/run — see step 5 |
| `onOneServer` / `withoutOverlapping` not working | These require a shared cache driver (`redis`, `database`, `memcached`) — not `array` or `file` |
| Config changes not applied | `php artisan config:clear` (or `config:cache` after changes in production) |

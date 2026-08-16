# Configuration Reference

All configuration lives in `config/database-backup.php` (published via `php artisan vendor:publish --tag=database-backup-config`). Every value can be set through environment variables — the config file only reads from `env()`.

> **Production rule:** never commit secrets. Keys, tokens, and webhook URLs belong in `.env` (or your secret manager), not in the published config file.

---

## Default driver

```dotenv
DB_BACKUP_DRIVER=local
```

The driver used when none is specified on the command line or in the `Manager` API. One of: `local`, `s3`, `google_drive`, `onedrive`.

## Drivers

### Local

```dotenv
DB_BACKUP_LOCAL_ROOT=            # default: storage_path('app/database-backup')
```

Files are written with `0640` permissions.

### S3

| Env var | Default | Description |
| --- | --- | --- |
| `AWS_ACCESS_KEY_ID` | — | Access key |
| `AWS_SECRET_ACCESS_KEY` | — | Secret key |
| `AWS_SESSION_TOKEN` | — | Optional session token (STS) |
| `AWS_DEFAULT_REGION` | `us-east-1` | Bucket region |
| `DB_BACKUP_S3_BUCKET` | — | **Required** bucket name |
| `DB_BACKUP_S3_PREFIX` | `backups` | Key prefix (folder) |
| `AWS_ENDPOINT` | — | Custom endpoint (MinIO / LocalStack) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | Path-style addressing for MinIO etc. |
| `DB_BACKUP_S3_MULTIPART_THRESHOLD` | `52428800` (50 MB) | Switch to multipart above this size |
| `DB_BACKUP_S3_PART_SIZE` | `8388608` (8 MB) | Multipart part size |
| `DB_BACKUP_S3_CONCURRENCY` | `3` | Parallel multipart parts |
| `DB_BACKUP_S3_STORAGE_CLASS` | `STANDARD` | `STANDARD`, `STANDARD_IA`, `GLACIER_IR`, … |
| `DB_BACKUP_S3_SSE` | — | `AES256` or `aws:kms` |
| `DB_BACKUP_S3_KMS_KEY_ID` | — | KMS key for `aws:kms` SSE |
| `DB_BACKUP_S3_VERSIONING` | `false` | Enable S3 versioning on the bucket |
| `DB_BACKUP_S3_RPS` | `10` | Request rate limit (throttling guard) |

### Google Drive

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_DRIVE_AUTH` | `service_account` | `service_account` or `oauth` |
| `DB_BACKUP_DRIVE_SERVICE_ACCOUNT_JSON` | — | Absolute path to the service-account JSON key file |
| `DB_BACKUP_DRIVE_CLIENT_ID` | — | OAuth client ID (oauth mode) |
| `DB_BACKUP_DRIVE_CLIENT_SECRET` | — | OAuth client secret (oauth mode) |
| `DB_BACKUP_DRIVE_REFRESH_TOKEN` | — | Refresh token (oauth mode) |
| `DB_BACKUP_DRIVE_FOLDER_ID` | — | Target folder ID (root when empty) |
| `DB_BACKUP_DRIVE_CHUNK_SIZE` | `8388608` (8 MB) | Resumable upload chunk size |
| `DB_BACKUP_DRIVE_RPS` | `5` | Request rate limit |

### OneDrive

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_ONEDRIVE_TENANT_ID` | — | Entra ID (Azure AD) tenant ID |
| `DB_BACKUP_ONEDRIVE_CLIENT_ID` | — | App registration client ID |
| `DB_BACKUP_ONEDRIVE_CLIENT_SECRET` | — | App registration client secret |
| `DB_BACKUP_ONEDRIVE_GRANT` | `client_credentials` | `client_credentials` (app-only) or `authorization_code` (delegated) |
| `DB_BACKUP_ONEDRIVE_REFRESH_TOKEN` | — | Refresh token (authorization_code mode) |
| `DB_BACKUP_ONEDRIVE_DRIVE` | `me` | `me`, `drive`, or `drives/{id}` |
| `DB_BACKUP_ONEDRIVE_FOLDER` | `backups` | Folder path inside the drive |
| `DB_BACKUP_ONEDRIVE_CHUNK_SIZE` | `10485760` (10 MB) | Upload chunk size (must be a multiple of 320 KB) |
| `DB_BACKUP_ONEDRIVE_RPS` | `5` | Request rate limit |

## Database

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_CONNECTION` | (app default) | Connection name to back up |
| `DB_BACKUP_DUMP_BINARY` | auto-detect | Path to the dump binary (`mysqldump`, `pg_dump`, …) |
| `DB_BACKUP_DUMP_TIMEOUT` | `3600` | Dump timeout in seconds |
| `DB_BACKUP_GZIP` | `true` | Gzip the dump |
| `DB_BACKUP_STREAMING` | `false` | Force the PDO streaming dumper |
| `DB_BACKUP_STREAMING_CHUNK` | `2000` | Rows per `SELECT` in streaming mode |

Streaming options `include_schema` and `include_data` are both `true` by default.

## Encryption

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_ENCRYPT` | `false` | Enable AES-256-GCM encryption |
| `DB_BACKUP_KEY` | — | **Required when enabled** — base64-encoded 32-byte key |
| `DB_BACKUP_ENC_CHUNK` | `1048576` (1 MB) | Plaintext bytes per encrypted chunk |
| `DB_BACKUP_GPG` | `false` | Enable GPG encryption (instead of / in addition to AES) |
| `DB_BACKUP_GPG_BINARY` | `gpg` | Path to the `gpg` binary |
| `DB_BACKUP_GPG_RECIPIENTS` | — | Comma-separated recipient key IDs/emails |
| `DB_BACKUP_GPG_PASSPHRASE` | — | Symmetric passphrase (when no recipients) |
| `DB_BACKUP_GPG_CIPHER` | `AES256` | GPG cipher algorithm |

Generate the AES key:

```bash
php -r "echo base64_encode(random_bytes(32));"
```

## Scheduling

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_SCHEDULE` | `true` | Register `db:backup` with the scheduler |
| `DB_BACKUP_SCHEDULE_EXPRESSION` | `0 2 * * *` | Cron expression (daily 02:00) |
| `DB_BACKUP_SCHEDULE_TIMEZONE` | `app.timezone` | Schedule timezone |
| `DB_BACKUP_SCHEDULE_ONE_SERVER` | `true` | `onOneServer` (needs shared cache) |
| `DB_BACKUP_SCHEDULE_NO_OVERLAP` | `true` | `withoutOverlapping` |
| `DB_BACKUP_SCHEDULE_EXPIRES` | `1440` | Overlap lock expiry (minutes) |
| `DB_BACKUP_SCHEDULE_LOG` | `storage/logs/database-backup-schedule.log` | Output log path |

> `onOneServer` and `withoutOverlapping` require a shared cache driver (`redis`, `database`, `memcached`). They silently no-op with `array` or `file` caches.

## Retention

Policies are **ANDed** — a backup is pruned when it violates *any* enabled policy. Set a value to `0` to disable that policy.

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_RETENTION` | `true` | Enable pruning |
| `DB_BACKUP_RETENTION_DAYS` | `14` | Delete backups older than N days |
| `DB_BACKUP_RETENTION_COUNT` | `30` | Keep at most N most-recent backups |
| `DB_BACKUP_RETENTION_MAX_SIZE` | `0` | Max total bytes (0 = unlimited) |
| `DB_BACKUP_PRUNE_ON_BACKUP` | `true` | Prune automatically after each backup |

## Storage usage alerts

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_ALERT_THRESHOLD_BYTES` | `0` | Fire `StorageUsageAlert` above this many bytes (0 = off) |
| `DB_BACKUP_ALERT_PERCENT` | `90` | Fire above this % of quota |
| `DB_BACKUP_QUOTA_BYTES` | `0` | Known quota (0 = unknown) |

## Notifications

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_NOTIFY_SUCCESS` | `false` | Notify on successful backups |
| `DB_BACKUP_NOTIFY_FAILURE` | `true` | Notify on failed backups |

### Slack

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_SLACK_ENABLED` | `false` | Enable Slack channel |
| `DB_BACKUP_SLACK_WEBHOOK_URL` | — | Incoming webhook URL |
| `DB_BACKUP_SLACK_CHANNEL` | — | Override channel |
| `DB_BACKUP_SLACK_USERNAME` | `Database Backup` | Webhook username |

### Email

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_EMAIL_ENABLED` | `false` | Enable email channel |
| `DB_BACKUP_EMAIL_TO` | — | Comma-separated recipients |
| `DB_BACKUP_EMAIL_FROM` | — | From address (defaults to app mail config) |

### Webhook

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_WEBHOOK_ENABLED` | `false` | Enable generic webhook channel |
| `DB_BACKUP_WEBHOOK_URL` | — | Endpoint URL |
| `DB_BACKUP_WEBHOOK_SECRET` | — | HMAC-SHA256 signing secret (sent as `X-Backup-Signature`) |
| `DB_BACKUP_WEBHOOK_TIMEOUT` | `10` | Request timeout (seconds) |

## Queue

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_QUEUE_CONNECTION` | (app default) | Queue connection |
| `DB_BACKUP_QUEUE` | `backups` | Queue name |
| `DB_BACKUP_QUEUE_TRIES` | `5` | Max attempts |
| `DB_BACKUP_QUEUE_TIMEOUT` | `3600` | Job timeout (seconds) |
| `DB_BACKUP_QUEUE_MAX_EXCEPTIONS` | `3` | Give up after N exceptions |

Backoff schedule (fixed): `[10, 30, 60, 120, 300]` seconds.

## Status endpoint

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_STATUS_ENABLED` | `false` | Enable `GET /database-backup/status` |
| `DB_BACKUP_STATUS_TOKEN` | — | **Required** bearer token |
| `DB_BACKUP_STATUS_PREFIX` | `database-backup` | URL prefix |
| `DB_BACKUP_STATUS_MIDDLEWARE` | `['api']` | Extra middleware (config only) |

Authenticate with the `X-Backup-Token` header or `?token=` query parameter.

## Filenames & temp storage

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_FILENAME_PREFIX` | `backup` | Filename prefix |
| `DB_BACKUP_FILENAME_DATE_FORMAT` | `Y-m-d_H-i-s` | Timestamp format |
| `DB_BACKUP_TEMP_PATH` | `storage/app/database-backup/tmp` | Temp working directory |

## History

| Env var | Default | Description |
| --- | --- | --- |
| `DB_BACKUP_HISTORY` | `true` | Record runs in `database_backup_runs` (requires the published migration) |

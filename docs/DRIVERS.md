# Drivers

The package ships with four drivers: `local`, `s3`, `google_drive`, and `onedrive`. Drivers are resolved lazily — only the ones you use need credentials configured.

Set the default with:

```dotenv
DB_BACKUP_DRIVER=local
```

You can also target specific drivers per run:

```bash
php artisan db:backup --driver=s3 --driver=google_drive
```

```php
DatabaseBackup::backup(['drivers' => ['s3', 'onedrive']]);
```

---

## Local

No credentials required. Backups are written to a local directory.

```dotenv
DB_BACKUP_DRIVER=local
DB_BACKUP_LOCAL_ROOT=            # default: storage_path('app/database-backup')
```

Files are created with `0640` permissions. Useful for:

- Development and testing
- A staging area before shipping to cold storage
- Servers where the "cloud" is a mounted network share

---

## S3 (AWS, MinIO, LocalStack)

Requires the `aws/aws-sdk-php` package:

```bash
composer require aws/aws-sdk-php
```

### AWS S3

```dotenv
DB_BACKUP_DRIVER=s3
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-1
DB_BACKUP_S3_BUCKET=my-company-backups
DB_BACKUP_S3_PREFIX=production
```

Recommended hardening:

```dotenv
# Server-side encryption
DB_BACKUP_S3_SSE=AES256
# or with KMS:
# DB_BACKUP_S3_SSE=aws:kms
# DB_BACKUP_S3_KMS_KEY_ID=arn:aws:kms:...

# Cheaper long-term storage
DB_BACKUP_S3_STORAGE_CLASS=STANDARD_IA

# Bucket versioning (protects against accidental deletes)
DB_BACKUP_S3_VERSIONING=true
```

### MinIO / LocalStack (S3-compatible)

```dotenv
DB_BACKUP_DRIVER=s3
AWS_ENDPOINT=http://localhost:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
DB_BACKUP_S3_BUCKET=backups
```

### Multipart uploads

Uploads larger than `DB_BACKUP_S3_MULTIPART_THRESHOLD` (default 50 MB) use the AWS SDK multipart uploader with parallel parts:

```dotenv
DB_BACKUP_S3_MULTIPART_THRESHOLD=52428800   # 50 MB
DB_BACKUP_S3_PART_SIZE=8388608              # 8 MB per part
DB_BACKUP_S3_CONCURRENCY=3                  # parallel parts
DB_BACKUP_S3_RPS=10                         # request rate limit
```

---

## Google Drive

Two authentication modes — **service account** (recommended for servers) and **OAuth2** (user-authorized).

### Service account (recommended)

1. Go to the [Google Cloud Console](https://console.cloud.google.com) → create a project (or pick one).
2. Enable the **Google Drive API**.
3. **APIs & Services → Credentials → Create Credentials → Service Account**.
4. Create a JSON key for the service account and download it to the server (e.g. `/etc/database-backup/drive-service-account.json`).
5. Create a folder in Drive, open its sharing settings, and share it with the service account's email (`...@<project>.iam.gserviceaccount.com`) as **Editor**.
6. Copy the folder ID from the URL (`https://drive.google.com/drive/folders/<FOLDER_ID>`).

```dotenv
DB_BACKUP_DRIVER=google_drive
DB_BACKUP_DRIVE_AUTH=service_account
DB_BACKUP_DRIVE_SERVICE_ACCOUNT_JSON=/etc/database-backup/drive-service-account.json
DB_BACKUP_DRIVE_FOLDER_ID=1AbCdEfGhIjKlMnOpQrStUvWxYz
```

The service account uses the `drive.file` scope — it can only see files it created or that were shared with it.

### OAuth2 (user-authorized)

1. Create an **OAuth Client ID** (Desktop app type) in the same project.
2. Complete the OAuth flow once to obtain a refresh token with the `https://www.googleapis.com/auth/drive.file` scope.
3. Store the client ID, secret, and refresh token.

```dotenv
DB_BACKUP_DRIVER=google_drive
DB_BACKUP_DRIVE_AUTH=oauth
DB_BACKUP_DRIVE_CLIENT_ID=...apps.googleusercontent.com
DB_BACKUP_DRIVE_CLIENT_SECRET=...
DB_BACKUP_DRIVE_REFRESH_TOKEN=...
DB_BACKUP_DRIVE_FOLDER_ID=1AbCdEfGhIjKlMnOpQrStUvWxYz
```

Uploads use resumable sessions with 8 MB chunks (`DB_BACKUP_DRIVE_CHUNK_SIZE`), rate-limited to 5 requests/second.

---

## OneDrive (Microsoft Graph)

Two grant types — **client credentials** (app-only, recommended) and **authorization code** (delegated).

### Client credentials (app-only)

1. In the [Azure portal](https://portal.azure.com) → **App registrations** → **New registration**.
2. Under **API permissions**, add the Microsoft Graph application permission **`Files.ReadWrite.All`** and grant admin consent.
3. Create a **client secret** under **Certificates & secrets**.
4. Note the **Directory (tenant) ID** and **Application (client) ID**.

```dotenv
DB_BACKUP_DRIVER=onedrive
DB_BACKUP_ONEDRIVE_TENANT_ID=00000000-0000-0000-0000-000000000000
DB_BACKUP_ONEDRIVE_CLIENT_ID=11111111-1111-1111-1111-111111111111
DB_BACKUP_ONEDRIVE_CLIENT_SECRET=...
DB_BACKUP_ONEDRIVE_GRANT=client_credentials
DB_BACKUP_ONEDRIVE_DRIVE=me
DB_BACKUP_ONEDRIVE_FOLDER=backups
```

> With `client_credentials`, `drive=me` targets the signed-in user's OneDrive. Use `drive=drive` for the SharePoint root or `drives/{id}` for a specific drive.

### Authorization code (delegated)

1. Register the app as above, but add the delegated permission **`Files.ReadWrite.All`**.
2. Complete the OAuth flow once to obtain a refresh token with the `offline_access` scope.

```dotenv
DB_BACKUP_DRIVER=onedrive
DB_BACKUP_ONEDRIVE_TENANT_ID=...
DB_BACKUP_ONEDRIVE_CLIENT_ID=...
DB_BACKUP_ONEDRIVE_CLIENT_SECRET=...
DB_BACKUP_ONEDRIVE_GRANT=authorization_code
DB_BACKUP_ONEDRIVE_REFRESH_TOKEN=...
DB_BACKUP_ONEDRIVE_FOLDER=backups
```

Uploads use resumable sessions with 10 MB chunks (`DB_BACKUP_ONEDRIVE_CHUNK_SIZE` — must be a multiple of 320 KB), rate-limited to 5 requests/second.

---

## Testing drivers

Verify connectivity for every configured driver (or a subset) with a probe upload:

```bash
# All configured drivers
php artisan db:backup:test

# Only specific drivers
php artisan db:backup:test --driver=s3 --driver=google_drive
```

Each driver uploads a small `.probe-*.txt` file, confirms it exists, then deletes it. Any failure is reported per driver:

```
+--------------+--------+------------------------------------------+
| Driver       | Status | Error                                    |
+--------------+--------+------------------------------------------+
| local        | ✅ OK  | —                                        |
| s3           | ✅ OK  | —                                        |
| google_drive | ❌ FAILED | Google Drive service account JSON key file not found |
+--------------+--------+------------------------------------------+
```

Run this after any credential change, and as part of your deployment checklist.

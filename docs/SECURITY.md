# Security & Encryption

## Encryption overview

Backups can be encrypted with **AES-256-GCM** (built in, streaming, authenticated) and optionally **GPG** (symmetric or recipient-based).

### AES-256-GCM (built in)

- Authenticated encryption — tampering is detected on decrypt.
- Streams in 1 MB chunks (`DB_BACKUP_ENC_CHUNK`), so memory stays flat even for multi-GB databases.
- The encrypted file has a small header (format version + cipher id) followed by per-chunk nonce + ciphertext + auth tag.

Enable it:

```dotenv
DB_BACKUP_ENCRYPT=true
DB_BACKUP_KEY=base64:...
```

Generate the key:

```bash
php -r "echo base64_encode(random_bytes(32));"
```

> The key is a **base64-encoded 32-byte value** — exactly 44 characters ending in `=`. Store it in `.env` or your secret manager. **If you lose the key, the backups are unrecoverable.** Keep a copy in your password manager / vault, separate from the server.

### GPG (optional)

Use GPG when you need recipient-based encryption (e.g. an auditor holds the private key) or want to interoperate with existing GPG tooling:

```dotenv
DB_BACKUP_GPG=true
DB_BACKUP_GPG_RECIPIENTS=audit@company.com,ops@company.com
# or symmetric:
# DB_BACKUP_GPG_PASSPHRASE=...
DB_BACKUP_GPG_CIPHER=AES256
```

Requires `ext-gnupg` or the `gpg` binary (`DB_BACKUP_GPG_BINARY` to override the path).

## Key management best practices

1. **Never commit keys** — `.env` is gitignored; the published config file should only reference `env()`.
2. **Rotate keys** — when rotating, keep the old key available until the oldest backup you still retain has been re-encrypted or expired.
3. **Separate storage** — the encryption key should not live on the same machine as the backups it protects (that defeats the purpose of off-site backups).
4. **Restrict access** — the key file / env var should be readable only by the web/CLI user and your deployment tooling.
5. **Test recovery** — verify you can decrypt a backup with the key from your vault, not just the one on the server.

## Webhook signing

When `DB_BACKUP_WEBHOOK_SECRET` is set, webhook notifications are signed with **HMAC-SHA256** and sent in the `X-Backup-Signature` header. Verify on the receiving end:

```php
// Receiver example (Laravel)
$signature = $request->header('X-Backup-Signature');
$expected = hash_hmac('sha256', $request->getContent(), config('services.backup_webhook_secret'));

abort_unless(hash_equals($expected, $signature), 403);
```

## Status endpoint protection

The status endpoint is disabled by default. When enabled, every request must present the bearer token:

```dotenv
DB_BACKUP_STATUS_ENABLED=true
DB_BACKUP_STATUS_TOKEN=$(openssl rand -hex 32)
```

```bash
curl -H "X-Backup-Token: $TOKEN" https://your-app.com/database-backup/status
# or ?token=$TOKEN
```

The token is compared with a constant-time comparison. Generate a long random value — never reuse the encryption key.

## File permissions

- Local backups are written with `0640` permissions (owner read/write, group read).
- The temp directory (`DB_BACKUP_TEMP_PATH`, default `storage/app/database-backup/tmp`) is cleaned after every run — plaintext dumps exist there only briefly.
- Ensure `storage/` is not web-accessible (Laravel's default `.htaccess`/nginx config already blocks it).

## Threat model notes

| Threat | Mitigation |
| --- | --- |
| Backup file stolen from cloud storage | AES-256-GCM encryption; key stored separately |
| Backup tampered in transit/at rest | GCM auth tags verified on decrypt; SHA-256 checksums in the manifest |
| Webhook endpoint spoofed | HMAC-SHA256 signature (`X-Backup-Signature`) |
| Status endpoint exposed | Bearer token, disabled by default |
| Accidental deletion of backups | S3 versioning (`DB_BACKUP_S3_VERSIONING=true`), retention policies, IAM least-privilege |
| Credentials leaked in repo | All secrets via `env()`; never hardcode in the published config |

## IAM least-privilege (S3)

The S3 user needs only:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::my-company-backups",
        "arn:aws:s3:::my-company-backups/*"
      ]
    }
  ]
}
```

## Google Drive / OneDrive scopes

- **Google Drive service account**: `drive.file` scope only — the account can only see files it created or that were shared with it. Share only the backup folder with it.
- **OneDrive app-only**: `Files.ReadWrite.All` application permission — scope the app registration to the specific drive/folder you use.

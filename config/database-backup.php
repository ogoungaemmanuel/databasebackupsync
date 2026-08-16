<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | The cloud driver used when none is specified on the command line or in
    | the Manager API. One of: local, s3, google_drive, onedrive.
    |
    */

    'default_driver' => env('DB_BACKUP_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Each driver is resolved lazily and only when used. Credentials should
    | always come from environment variables — never commit secrets.
    |
    */

    'drivers' => [

        'local' => [
            'root' => env('DB_BACKUP_LOCAL_ROOT', storage_path('app/database-backup')),
            'permissions' => 0640,
        ],

        's3' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'token' => env('AWS_SESSION_TOKEN'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('DB_BACKUP_S3_BUCKET'),
            'prefix' => env('DB_BACKUP_S3_PREFIX', 'backups'),
            'endpoint' => env('AWS_ENDPOINT'), // optional: MinIO / LocalStack
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'multipart_threshold' => env('DB_BACKUP_S3_MULTIPART_THRESHOLD', 52428800), // 50 MB
            'part_size' => env('DB_BACKUP_S3_PART_SIZE', 8388608), // 8 MB
            'concurrency' => env('DB_BACKUP_S3_CONCURRENCY', 3),
            'storage_class' => env('DB_BACKUP_S3_STORAGE_CLASS', 'STANDARD'), // STANDARD_IA, GLACIER_IR...
            'server_side_encryption' => env('DB_BACKUP_S3_SSE', null), // AES256 | aws:kms
            'sse_kms_key_id' => env('DB_BACKUP_S3_KMS_KEY_ID'),
            'versioning' => env('DB_BACKUP_S3_VERSIONING', false),
            'requests_per_second' => env('DB_BACKUP_S3_RPS', 10),
        ],

        'google_drive' => [
            // Service account (recommended) or OAuth2 client credentials.
            'auth' => env('DB_BACKUP_DRIVE_AUTH', 'service_account'), // service_account | oauth
            'service_account_json' => env('DB_BACKUP_DRIVE_SERVICE_ACCOUNT_JSON'), // path to JSON key file
            'client_id' => env('DB_BACKUP_DRIVE_CLIENT_ID'),
            'client_secret' => env('DB_BACKUP_DRIVE_CLIENT_SECRET'),
            'refresh_token' => env('DB_BACKUP_DRIVE_REFRESH_TOKEN'),
            'folder_id' => env('DB_BACKUP_DRIVE_FOLDER_ID'), // optional: root when empty
            'chunk_size' => env('DB_BACKUP_DRIVE_CHUNK_SIZE', 8388608), // 8 MB
            'requests_per_second' => env('DB_BACKUP_DRIVE_RPS', 5),
        ],

        'onedrive' => [
            'tenant_id' => env('DB_BACKUP_ONEDRIVE_TENANT_ID'),
            'client_id' => env('DB_BACKUP_ONEDRIVE_CLIENT_ID'),
            'client_secret' => env('DB_BACKUP_ONEDRIVE_CLIENT_SECRET'),
            // client_credentials (app-only) or authorization_code (delegated).
            'grant_type' => env('DB_BACKUP_ONEDRIVE_GRANT', 'client_credentials'),
            'refresh_token' => env('DB_BACKUP_ONEDRIVE_REFRESH_TOKEN'),
            'drive' => env('DB_BACKUP_ONEDRIVE_DRIVE', 'me'), // me | drive | drives/{id}
            'folder_path' => env('DB_BACKUP_ONEDRIVE_FOLDER', 'backups'),
            'chunk_size' => env('DB_BACKUP_ONEDRIVE_CHUNK_SIZE', 10485760), // 10 MB (multiple of 320 KB)
            'requests_per_second' => env('DB_BACKUP_ONEDRIVE_RPS', 5),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The connection to back up (defaults to the app's default connection).
    | Binary dumpers are preferred; the streaming fallback is used when the
    | binary is missing or --streaming is passed.
    |
    */

    'database' => [
        'connection' => env('DB_BACKUP_CONNECTION'), // null = default connection
        'dump' => [
            'binary_path' => env('DB_BACKUP_DUMP_BINARY'), // auto-detect when null
            'timeout' => env('DB_BACKUP_DUMP_TIMEOUT', 3600), // seconds
            'gzip' => env('DB_BACKUP_GZIP', true),
            'streaming' => [
                'enabled' => env('DB_BACKUP_STREAMING', false), // force streaming fallback
                'chunk_size' => env('DB_BACKUP_STREAMING_CHUNK', 2000), // rows per SELECT
                'include_schema' => true,
                'include_data' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | AES-256-GCM (authenticated, streaming) is built in. GPG is optional.
    | The key is a base64-encoded 32-byte value; generate with:
    |   php -r "echo base64_encode(random_bytes(32));"
    |
    */

    'encryption' => [
        'enabled' => env('DB_BACKUP_ENCRYPT', false),
        'key' => env('DB_BACKUP_KEY'),
        'cipher' => 'aes-256-gcm',
        'chunk_size' => env('DB_BACKUP_ENC_CHUNK', 1048576), // 1 MB plaintext per chunk
        'gpg' => [
            'enabled' => env('DB_BACKUP_GPG', false),
            'binary' => env('DB_BACKUP_GPG_BINARY', 'gpg'),
            'recipients' => array_filter(array_map('trim', explode(',', (string) env('DB_BACKUP_GPG_RECIPIENTS', '')))),
            'passphrase' => env('DB_BACKUP_GPG_PASSPHRASE'),
            'cipher_algo' => env('DB_BACKUP_GPG_CIPHER', 'AES256'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | The package registers the db:backup command with Laravel's scheduler
    | when enabled. on_one_server / without_overlapping require a shared
    | cache driver (redis, database, memcached) — not 'array' or 'file'.
    |
    */

    'scheduling' => [
        'enabled' => env('DB_BACKUP_SCHEDULE', true),
        'expression' => env('DB_BACKUP_SCHEDULE_EXPRESSION', '0 2 * * *'), // daily at 02:00
        'timezone' => env('DB_BACKUP_SCHEDULE_TIMEZONE', config('app.timezone')),
        'on_one_server' => env('DB_BACKUP_SCHEDULE_ONE_SERVER', true),
        'without_overlapping' => env('DB_BACKUP_SCHEDULE_NO_OVERLAP', true),
        'expires_at' => env('DB_BACKUP_SCHEDULE_EXPIRES', 1440), // minutes
        'log_output' => env('DB_BACKUP_SCHEDULE_LOG', storage_path('logs/database-backup-schedule.log')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Policies are ANDed: a backup is pruned when it violates any enabled
    | policy. Set a value to 0 to disable that policy.
    |
    */

    'retention' => [
        'enabled' => env('DB_BACKUP_RETENTION', true),
        'days' => env('DB_BACKUP_RETENTION_DAYS', 14),
        'count' => env('DB_BACKUP_RETENTION_COUNT', 30),
        'max_total_size' => env('DB_BACKUP_RETENTION_MAX_SIZE', 0), // bytes, 0 = unlimited
        'prune_on_backup' => env('DB_BACKUP_PRUNE_ON_BACKUP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage usage alerts
    |--------------------------------------------------------------------------
    |
    | Fires StorageUsageAlert when a driver's total usage exceeds the
    | threshold. Percent is relative to the configured quota (0 = unknown).
    |
    */

    'storage_usage' => [
        'alert_threshold_bytes' => env('DB_BACKUP_ALERT_THRESHOLD_BYTES', 0),
        'alert_threshold_percent' => env('DB_BACKUP_ALERT_PERCENT', 90),
        'quota_bytes' => env('DB_BACKUP_QUOTA_BYTES', 0), // 0 = unknown
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Slack (incoming webhook), email (Laravel mail), and generic webhook.
    | on_success / on_failure gate which events produce notifications.
    |
    */

    'notifications' => [
        'on_success' => env('DB_BACKUP_NOTIFY_SUCCESS', false),
        'on_failure' => env('DB_BACKUP_NOTIFY_FAILURE', true),
        'channels' => [
            'slack' => [
                'enabled' => env('DB_BACKUP_SLACK_ENABLED', false),
                'webhook_url' => env('DB_BACKUP_SLACK_WEBHOOK_URL'),
                'channel' => env('DB_BACKUP_SLACK_CHANNEL'),
                'username' => env('DB_BACKUP_SLACK_USERNAME', 'Database Backup'),
            ],
            'email' => [
                'enabled' => env('DB_BACKUP_EMAIL_ENABLED', false),
                'to' => array_filter(array_map('trim', explode(',', (string) env('DB_BACKUP_EMAIL_TO', '')))),
                'from' => env('DB_BACKUP_EMAIL_FROM'),
            ],
            'webhook' => [
                'enabled' => env('DB_BACKUP_WEBHOOK_ENABLED', false),
                'url' => env('DB_BACKUP_WEBHOOK_URL'),
                'secret' => env('DB_BACKUP_WEBHOOK_SECRET'), // sent as X-Backup-Signature (HMAC-SHA256)
                'timeout' => env('DB_BACKUP_WEBHOOK_TIMEOUT', 10),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | When --queue is used, the backup pipeline runs as a queued job with
    | exponential backoff. Uploads are dispatched as independent jobs so a
    | single driver failure does not lose the whole backup.
    |
    */

    'queue' => [
        'connection' => env('DB_BACKUP_QUEUE_CONNECTION'), // null = default
        'queue' => env('DB_BACKUP_QUEUE', 'backups'),
        'tries' => env('DB_BACKUP_QUEUE_TRIES', 5),
        'backoff' => [10, 30, 60, 120, 300], // seconds between attempts
        'timeout' => env('DB_BACKUP_QUEUE_TIMEOUT', 3600),
        'max_exceptions' => env('DB_BACKUP_QUEUE_MAX_EXCEPTIONS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status endpoint
    |--------------------------------------------------------------------------
    |
    | GET /database-backup/status returns JSON with the last run, storage
    | usage, and metrics. Protected by a bearer token (X-Backup-Token header
    | or ?token= query param). Disabled by default.
    |
    */

    'status' => [
        'enabled' => env('DB_BACKUP_STATUS_ENABLED', false),
        'token' => env('DB_BACKUP_STATUS_TOKEN'),
        'prefix' => env('DB_BACKUP_STATUS_PREFIX', 'database-backup'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filenames & temp storage
    |--------------------------------------------------------------------------
    */

    'filename' => [
        'prefix' => env('DB_BACKUP_FILENAME_PREFIX', 'backup'),
        'date_format' => env('DB_BACKUP_FILENAME_DATE_FORMAT', 'Y-m-d_H-i-s'),
    ],

    'temp_path' => env('DB_BACKUP_TEMP_PATH', storage_path('app/database-backup/tmp')),

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | When the migration is published, each run is recorded in the
    | database_backup_runs table and exposed via the status endpoint.
    |
    */

    'history' => [
        'enabled' => env('DB_BACKUP_HISTORY', true),
    ],

];

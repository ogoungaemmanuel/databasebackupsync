<?php

namespace DatabaseBackupSync\Support;

use DatabaseBackupSync\Models\BackupRun;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

class BackupRunRecorder
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * Record a run in the history table when enabled and migrated.
     *
     * @param  array<int, string>  $drivers
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $status,
        ?string $connection,
        string $label,
        ?string $file,
        int $size,
        ?string $checksum,
        int $durationMs,
        array $drivers,
        array $metadata = [],
    ): ?BackupRun {
        $config = $this->app['config'];

        if (! $config->get('database-backup.history.enabled', true)) {
            return null;
        }

        try {
            if (! Schema::hasTable('database_backup_runs')) {
                return null;
            }

            return BackupRun::create([
                'status' => $status,
                'connection' => $connection,
                'label' => $label,
                'file' => $file,
                'size' => $size,
                'checksum' => $checksum,
                'duration_ms' => $durationMs,
                'drivers' => $drivers,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            // History is best-effort; never fail a backup because of it.
            logger()->warning('database-backup: failed to record run history', ['error' => $e->getMessage()]);

            return null;
        }
    }
}

<?php

namespace DatabaseBackupSync\Notifications\Listeners;

use DatabaseBackupSync\Events\BackupCompleted;
use DatabaseBackupSync\Events\BackupFailed;
use DatabaseBackupSync\Events\BackupPruned;
use DatabaseBackupSync\Events\BackupUploaded;
use DatabaseBackupSync\Events\BackupUploadFailed;
use DatabaseBackupSync\Events\StorageUsageAlert;
use DatabaseBackupSync\Notifications\Notifier;
use Illuminate\Contracts\Container\Container;

class BackupNotificationListener
{
    public function __construct(protected Container $app)
    {
    }

    public function handle(object $event): void
    {
        $config = $this->app['config']->get('database-backup.notifications', []);
        $notifier = $this->app->make(Notifier::class);

        if ($event instanceof BackupCompleted) {
            if (! $config['on_success'] ?? false) {
                return;
            }

            $r = $event->result;
            $notifier->send([
                'subject' => '✅ Database backup completed',
                'text' => sprintf('Backup [%s] (%s) completed in %.1fs.', $r->file, $this->humanBytes($r->size), $r->durationMs / 1000),
                'level' => 'success',
                'fields' => [
                    'File' => $r->file,
                    'Size' => $this->humanBytes($r->size),
                    'SHA-256' => substr($r->checksum, 0, 16).'…',
                    'Encrypted' => $r->encrypted ? 'yes' : 'no',
                    'Uploaded to' => implode(', ', array_map(fn ($u) => $u->driver, $r->uploads)),
                ],
            ]);

            return;
        }

        if ($event instanceof BackupFailed) {
            if (! $config['on_failure'] ?? true) {
                return;
            }

            $notifier->send([
                'subject' => '❌ Database backup FAILED',
                'text' => $event->exception->getMessage(),
                'level' => 'error',
                'fields' => [
                    'Connection' => (string) $event->connection,
                    'Label' => $event->label,
                ],
            ]);

            return;
        }

        if ($event instanceof BackupUploadFailed) {
            if (! $config['on_failure'] ?? true) {
                return;
            }

            $notifier->send([
                'subject' => '⚠️ Backup upload failed',
                'text' => sprintf('Upload to [%s] failed for [%s]: %s', $event->driver, $event->path, $event->exception->getMessage()),
                'level' => 'warning',
            ]);

            return;
        }

        if ($event instanceof StorageUsageAlert) {
            $notifier->send([
                'subject' => '🗄️ Backup storage usage alert',
                'text' => sprintf(
                    'Driver [%s] uses %s across %d files (%.1f%% of quota).',
                    $event->driver,
                    $this->humanBytes($event->totalBytes),
                    $event->fileCount,
                    $event->percentUsed
                ),
                'level' => 'warning',
            ]);

            return;
        }

        if ($event instanceof BackupPruned) {
            if (! $config['on_success'] ?? false) {
                return;
            }

            $notifier->send([
                'subject' => '🧹 Backup retention pruned',
                'text' => sprintf('Pruned %d backup(s) (%s).', count($event->pruned), $this->humanBytes(array_sum(array_column($event->pruned, 'size')))),
                'level' => 'info',
            ]);
        }
    }

    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}

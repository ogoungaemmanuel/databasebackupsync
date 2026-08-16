<?php

namespace DatabaseBackupSync\Jobs;

use DatabaseBackupSync\Drivers\DriverFactory;
use DatabaseBackupSync\Events\BackupUploaded;
use DatabaseBackupSync\Events\BackupUploadFailed;
use DatabaseBackupSync\Support\Checksum;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Upload an existing local dump file to a single driver. Used to re-upload
 * after a driver outage, or to fan out uploads independently so one driver's
 * failure never loses the backup. Passes a resume_state path so S3 multipart
 * uploads resume across retries instead of restarting.
 */
class UploadBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $localPath,
        public string $remotePath,
        public string $driver,
        public array $options = [],
    ) {
    }

    public function handle(DriverFactory $factory): void
    {
        $config = config("database-backup.drivers.{$this->driver}");

        if ($config === null) {
            throw new \RuntimeException("Driver [{$this->driver}] is not configured.");
        }

        $driver = $factory->make($this->driver, $config);

        try {
            $result = $driver->upload($this->localPath, $this->remotePath, [
                'manifest' => $this->options['manifest'] ?? null,
                'resume_state' => $this->resumeStatePath(),
                'label' => $this->options['label'] ?? 'reupload',
            ]);

            event(new BackupUploaded($result->driver, $result->path, $result->size, $result->checksum, $result->multipart));
        } catch (Throwable $e) {
            event(new BackupUploadFailed($this->driver, $this->remotePath, $e));
            throw $e;
        }
    }

    public function backoff(): array
    {
        return config('database-backup.queue.backoff', [10, 30, 60, 120, 300]);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function tags(): array
    {
        return ['database-backup', 'upload', $this->driver];
    }

    protected function resumeStatePath(): string
    {
        $base = (string) config('database-backup.temp_path', storage_path('app/database-backup/tmp'));

        return rtrim($base, '/\\').'/resume-'.sha1($this->driver.'|'.$this->remotePath).'.state';
    }
}

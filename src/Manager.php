<?php

namespace DatabaseBackupSync;

use DatabaseBackupSync\Drivers\DriverContract;
use DatabaseBackupSync\Drivers\DriverFactory;
use DatabaseBackupSync\Dumpers\DumperFactory;
use DatabaseBackupSync\Encryption\EncryptionManager;
use DatabaseBackupSync\Events\BackupCompleted;
use DatabaseBackupSync\Events\BackupFailed;
use DatabaseBackupSync\Events\BackupStarted;
use DatabaseBackupSync\Events\BackupUploaded;
use DatabaseBackupSync\Events\BackupUploadFailed;
use DatabaseBackupSync\Exceptions\DriverNotFoundException;
use DatabaseBackupSync\Exceptions\DumpFailedException;
use DatabaseBackupSync\Support\BackupResult;
use DatabaseBackupSync\Support\BackupRunRecorder;
use DatabaseBackupSync\Support\Checksum;
use DatabaseBackupSync\Support\FilenameGenerator;
use DatabaseBackupSync\Support\Manifest;
use DatabaseBackupSync\Support\Metrics;
use DatabaseBackupSync\Support\PruneResult;
use DatabaseBackupSync\Support\RestoreResult;
use DatabaseBackupSync\Support\RetentionPolicy;
use DatabaseBackupSync\Support\StorageUsage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

class Manager
{
    public function __construct(
        protected Container $app,
    ) {
    }

    /**
     * Run a full backup: dump → compress → encrypt → upload → record → prune.
     *
     * @param  array{drivers?: array<int, string>|string, encrypt?: bool, connection?: string, label?: string, streaming?: bool, gzip?: bool, prune?: bool, filename?: string}  $options
     */
    public function backup(array $options = []): BackupResult
    {
        $config = $this->app['config'];
        $startedAt = microtime(true);

        $connection = $options['connection'] ?? $config->get('database-backup.database.connection');
        $label = $options['label'] ?? 'scheduled';
        $drivers = $this->resolveDrivers($options['drivers'] ?? $config->get('database-backup.default_driver'));

        $this->events()->dispatch(new BackupStarted($connection, $label));

        $tempDir = $this->tempDir();
        $tempFile = null;

        try {
            // 1. Dump the database to a temp file (optionally gzipped).
            $dumper = $this->app->make(DumperFactory::class)->make($connection, [
                'streaming' => (bool) ($options['streaming'] ?? $config->get('database-backup.database.dump.streaming.enabled', false)),
                'gzip' => (bool) ($options['gzip'] ?? $config->get('database-backup.database.dump.gzip', true)),
            ]);

            $tempFile = $dumper->dump($tempDir);
            $this->metrics()->increment('dumps.completed', 1, ['driver' => $dumper->driverName()]);

            // 2. Encrypt when enabled.
            $encrypted = (bool) ($options['encrypt'] ?? $config->get('database-backup.encryption.enabled', false));
            if ($encrypted) {
                $tempFile = $this->app->make(EncryptionManager::class)->encryptFile($tempFile, $tempDir);
            }

            // 3. Compute integrity metadata.
            $size = filesize($tempFile);
            $checksum = Checksum::hashFile($tempFile);

            // 4. Build the remote filename + manifest.
            $filename = $options['filename'] ?? FilenameGenerator::make($connection, $config->get('database-backup.filename'), $encrypted);
            $manifest = Manifest::create($filename, $size, $checksum, $connection, $encrypted, $dumper->driverName());

            // 5. Upload to each driver (atomic, independent failures).
            $uploads = [];
            foreach ($drivers as $driverName) {
                try {
                    $driver = $this->driver($driverName);
                    $result = $driver->upload($tempFile, $filename, [
                        'manifest' => $manifest->toArray(),
                        'label' => $label,
                    ]);
                    $uploads[] = $result;
                    $this->metrics()->increment('uploads.completed', 1, ['driver' => $driverName]);
                    $this->metrics()->gauge('uploads.bytes', $result->size, ['driver' => $driverName]);
                    $this->events()->dispatch(new BackupUploaded($driverName, $result->path, $result->size, $result->checksum, $result->multipart));
                } catch (Throwable $e) {
                    $this->metrics()->increment('uploads.failed', 1, ['driver' => $driverName]);
                    $this->events()->dispatch(new BackupUploadFailed($driverName, $filename, $e));
                    logger()->error('database-backup: upload failed', [
                        'driver' => $driverName,
                        'file' => $filename,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($uploads === []) {
                throw new DumpFailedException('No backup was uploaded to any driver. Check the driver configuration and logs.');
            }

            // 6. Record history.
            $run = $this->app->make(BackupRunRecorder::class)->record(
                status: 'completed',
                connection: $connection,
                label: $label,
                file: $filename,
                size: $size,
                checksum: $checksum,
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                drivers: array_map(fn ($u) => $u->driver, $uploads),
                metadata: ['encrypted' => $encrypted, 'dumper' => $dumper->driverName()],
            );

            $result = new BackupResult(
                file: $filename,
                size: $size,
                checksum: $checksum,
                encrypted: $encrypted,
                connection: $connection,
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                uploads: $uploads,
                runId: $run?->id,
            );

            $this->metrics()->increment('backups.completed', 1, ['connection' => $connection]);
            $this->metrics()->gauge('backups.last_duration_ms', $result->durationMs, ['connection' => $connection]);

            $this->events()->dispatch(new BackupCompleted($result));

            // 7. Prune when configured.
            if ($options['prune'] ?? $config->get('database-backup.retention.prune_on_backup', true)) {
                $this->prune(array_map(fn ($u) => $u->driver, $uploads));
            }

            return $result;
        } catch (Throwable $e) {
            $this->metrics()->increment('backups.failed', 1, ['connection' => $connection]);
            $this->app->make(BackupRunRecorder::class)->record(
                status: 'failed',
                connection: $connection,
                label: $label,
                file: null,
                size: 0,
                checksum: null,
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                drivers: [],
                metadata: ['error' => $e->getMessage()],
            );
            $this->events()->dispatch(new BackupFailed($connection, $label, $e));

            throw $e;
        } finally {
            if ($tempFile !== null && is_file($tempFile)) {
                @unlink($tempFile);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * Apply the retention policy across the given drivers.
     */
    public function prune(?array $drivers = null): PruneResult
    {
        $config = $this->app['config'];

        if (! $config->get('database-backup.retention.enabled', true)) {
            return new PruneResult([], []);
        }

        $drivers = $drivers ?? $this->configuredDrivers();
        $policy = new RetentionPolicy($config->get('database-backup.retention', []));
        $pruned = [];
        $errors = [];

        foreach ($drivers as $driverName) {
            try {
                $driver = $this->driver($driverName);
                $files = $driver->list($this->prefixFor($driverName));
                $toDelete = $policy->selectForPruning($files);

                foreach ($toDelete as $file) {
                    $driver->delete($file->path);
                    $pruned[] = ['driver' => $driverName, 'path' => $file->path, 'size' => $file->size];
                    $this->metrics()->increment('pruned.files', 1, ['driver' => $driverName]);
                    $this->metrics()->gauge('pruned.bytes', $file->size, ['driver' => $driverName]);
                }

                $this->checkStorageUsage($driverName, $driver);
            } catch (Throwable $e) {
                $errors[$driverName] = $e->getMessage();
                logger()->error('database-backup: prune failed', ['driver' => $driverName, 'error' => $e->getMessage()]);
            }
        }

        return new PruneResult($pruned, $errors);
    }

    /**
     * List backups across a driver (or all configured drivers).
     */
    public function listBackups(?string $driver = null): array
    {
        $drivers = $driver !== null ? [$driver] : $this->configuredDrivers();
        $out = [];

        foreach ($drivers as $driverName) {
            $out[$driverName] = $this->driver($driverName)->list($this->prefixFor($driverName));
        }

        return $out;
    }

    /**
     * Download, decrypt, and restore a backup into the given connection.
     */
    public function restore(string $remotePath, array $options = []): RestoreResult
    {
        $driverName = $options['driver'] ?? $this->app['config']->get('database-backup.default_driver');
        $connection = $options['connection'] ?? $this->app['config']->get('database-backup.database.connection');
        $decrypt = (bool) ($options['decrypt'] ?? $this->app['config']->get('database-backup.encryption.enabled', false));

        $tempDir = $this->tempDir();
        $localPath = $tempDir.'/'.basename($remotePath);

        try {
            $this->driver($driverName)->download($remotePath, $localPath);

            if ($decrypt) {
                $localPath = $this->app->make(EncryptionManager::class)->decryptFile($localPath, $tempDir);
            }

            $dumper = $this->app->make(DumperFactory::class)->make($connection, ['gzip' => false]);
            $dumper->restore($localPath);

            return new RestoreResult($remotePath, $driverName, $connection, $localPath);
        } finally {
            if (is_file($localPath)) {
                @unlink($localPath);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * Verify connectivity to each driver by uploading and deleting a probe file.
     */
    public function testDrivers(?array $drivers = null): array
    {
        $drivers = $drivers ?? $this->configuredDrivers();
        $results = [];

        foreach ($drivers as $driverName) {
            try {
                $driver = $this->driver($driverName);
                $probe = '.probe-'.bin2hex(random_bytes(4)).'.txt';
                $tmp = tempnam(sys_get_temp_dir(), 'dbsync-probe');
                file_put_contents($tmp, 'database-backup-sync probe '.date('c'));

                $driver->upload($tmp, $probe);
                $exists = $driver->exists($probe);
                $driver->delete($probe);

                @unlink($tmp);
                $results[$driverName] = ['ok' => $exists, 'error' => $exists ? null : 'probe file not found after upload'];
            } catch (Throwable $e) {
                $results[$driverName] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Resolve a driver instance by name.
     */
    public function driver(string $name): DriverContract
    {
        $config = $this->app['config']->get("database-backup.drivers.{$name}");

        if ($config === null) {
            throw DriverNotFoundException::forDriver($name);
        }

        return $this->app->make(DriverFactory::class)->make($name, $config);
    }

    /**
     * Names of all configured drivers.
     */
    public function drivers(): array
    {
        return $this->configuredDrivers();
    }

    public function metrics(): Metrics
    {
        return $this->app->make(Metrics::class);
    }

    /**
     * @return array<int, string>
     */
    protected function configuredDrivers(): array
    {
        return array_keys($this->app['config']->get('database-backup.drivers', []));
    }

    /**
     * @param  array<int, string>|string  $drivers
     * @return array<int, string>
     */
    protected function resolveDrivers(array|string $drivers): array
    {
        $names = is_array($drivers) ? $drivers : explode(',', $drivers);
        $names = array_values(array_filter(array_map('trim', $names)));

        if ($names === []) {
            $names = $this->configuredDrivers();
        }

        foreach ($names as $name) {
            if (! $this->app['config']->has("database-backup.drivers.{$name}")) {
                throw DriverNotFoundException::forDriver($name);
            }
        }

        return $names;
    }

    protected function prefixFor(string $driverName): string
    {
        return (string) $this->app['config']->get("database-backup.drivers.{$driverName}.prefix", '');
    }

    protected function checkStorageUsage(string $driverName, DriverContract $driver): void
    {
        $config = $this->app['config']->get('database-backup.storage_usage', []);
        $usage = StorageUsage::measure($driver, $this->prefixFor($driverName));

        $thresholdBytes = (int) ($config['alert_threshold_bytes'] ?? 0);
        $thresholdPercent = (int) ($config['alert_threshold_percent'] ?? 90);
        $quota = (int) ($config['quota_bytes'] ?? 0);

        $percent = $quota > 0 ? ($usage->totalBytes / $quota) * 100 : 0.0;
        $alert = ($thresholdBytes > 0 && $usage->totalBytes >= $thresholdBytes)
            || ($quota > 0 && $percent >= $thresholdPercent);

        if ($alert) {
            $this->events()->dispatch(new \DatabaseBackupSync\Events\StorageUsageAlert(
                $driverName,
                $usage->totalBytes,
                $usage->fileCount,
                $quota,
                $percent
            ));
        }
    }

    protected function tempDir(): string
    {
        $base = (string) $this->app['config']->get('database-backup.temp_path', storage_path('app/database-backup/tmp'));
        $dir = rtrim($base, '/\\').'/'.date('Ymd-His').'-'.bin2hex(random_bytes(4));

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create backup temp directory [{$dir}].");
        }

        return $dir;
    }

    protected function events(): Dispatcher
    {
        return $this->app->make(Dispatcher::class);
    }
}

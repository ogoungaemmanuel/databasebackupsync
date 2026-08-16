<?php

namespace DatabaseBackupSync\Commands;

use DatabaseBackupSync\Jobs\RunBackupJob;
use DatabaseBackupSync\Manager;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'db:backup
        {--driver=* : Cloud driver(s) to upload to (local, s3, google_drive, onedrive). Repeatable.}
        {--connection= : Database connection to back up (defaults to the app default).}
        {--encrypt : Force encryption on.}
        {--no-encrypt : Force encryption off.}
        {--queue : Dispatch the backup as a queued job.}
        {--streaming : Force the PDO streaming dumper instead of the native binary.}
        {--gzip : Force gzip compression on.}
        {--no-gzip : Force gzip compression off.}
        {--prune : Run retention pruning after the backup.}
        {--no-prune : Skip retention pruning.}
        {--label= : Label for this run (default: scheduled).}
        {--filename= : Override the remote filename.}';

    protected $description = 'Back up the database and upload to configured cloud drivers';

    public function handle(Manager $manager): int
    {
        $options = [
            'connection' => $this->option('connection'),
            'label' => $this->option('label') ?? 'manual',
            'filename' => $this->option('filename'),
            'streaming' => (bool) $this->option('streaming'),
        ];

        if ($this->option('driver') !== []) {
            $options['drivers'] = $this->option('driver');
        }

        if ($this->option('encrypt')) {
            $options['encrypt'] = true;
        } elseif ($this->option('no-encrypt')) {
            $options['encrypt'] = false;
        }

        if ($this->option('gzip')) {
            $options['gzip'] = true;
        } elseif ($this->option('no-gzip')) {
            $options['gzip'] = false;
        }

        if ($this->option('prune')) {
            $options['prune'] = true;
        } elseif ($this->option('no-prune')) {
            $options['prune'] = false;
        }

        if ($this->option('queue')) {
            $queue = config('database-backup.queue.queue', 'backups');
            $connection = config('database-backup.queue.connection');

            RunBackupJob::dispatch($options)
                ->onQueue($queue)
                ->onConnection($connection);

            $this->info('Backup dispatched to the queue.');

            return self::SUCCESS;
        }

        $started = microtime(true);

        try {
            $result = $manager->backup($options);
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup completed in %.1fs: %s (%s, %s)',
            microtime(true) - $started,
            $result->file,
            $this->humanBytes($result->size),
            $result->encrypted ? 'encrypted' : 'plaintext'
        ));

        $rows = [];

        foreach ($result->uploads as $upload) {
            $rows[] = [
                $upload->driver,
                $upload->path,
                $this->humanBytes($upload->size),
                $upload->multipart ? 'multipart' : 'single',
                substr($upload->checksum ?? '', 0, 12).'…',
            ];
        }

        $this->table(['Driver', 'File', 'Size', 'Mode', 'SHA-256'], $rows);

        return self::SUCCESS;
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

<?php

namespace DatabaseBackupSync\Commands;

use DatabaseBackupSync\Manager;
use Illuminate\Console\Command;

class RestoreCommand extends Command
{
    protected $signature = 'db:backup:restore
        {file : Remote backup filename to restore.}
        {--driver= : Driver to download from (defaults to the default driver).}
        {--connection= : Database connection to restore into.}
        {--decrypt : Decrypt the backup before restoring.}
        {--no-decrypt : Do not decrypt (backup is plaintext).}';

    protected $description = 'Download, decrypt, and restore a backup into the database';

    public function handle(Manager $manager): int
    {
        $options = [
            'driver' => $this->option('driver'),
            'connection' => $this->option('connection'),
        ];

        if ($this->option('decrypt')) {
            $options['decrypt'] = true;
        } elseif ($this->option('no-decrypt')) {
            $options['decrypt'] = false;
        }

        $this->warn('Restoring will OVERWRITE data in the target database. This is not reversible.');

        if (! $this->confirm('Are you sure you want to continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $result = $manager->restore($this->argument('file'), $options);
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restored [%s] from [%s] into connection [%s].',
            $result->remotePath,
            $result->driver,
            $result->connection ?? 'default'
        ));

        return self::SUCCESS;
    }
}

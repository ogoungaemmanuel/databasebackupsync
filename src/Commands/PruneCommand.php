<?php

namespace DatabaseBackupSync\Commands;

use DatabaseBackupSync\Jobs\PruneBackupsJob;
use DatabaseBackupSync\Manager;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'db:backup:prune
        {--driver=* : Only prune these drivers. Repeatable.}
        {--queue : Dispatch pruning as a queued job.}';

    protected $description = 'Apply the retention policy and delete expired backups';

    public function handle(Manager $manager): int
    {
        $drivers = $this->option('driver') !== [] ? $this->option('driver') : null;

        if ($this->option('queue')) {
            PruneBackupsJob::dispatch($drivers)
                ->onQueue(config('database-backup.queue.queue', 'backups'))
                ->onConnection(config('database-backup.queue.connection'));

            $this->info('Pruning dispatched to the queue.');

            return self::SUCCESS;
        }

        $result = $manager->prune($drivers);

        if ($result->errors !== []) {
            foreach ($result->errors as $driver => $error) {
                $this->error("Prune failed for [{$driver}]: {$error}");
            }
        }

        $this->info(sprintf('Pruned %d backup(s), freeing %s.', $result->prunedCount(), $this->humanBytes($result->prunedBytes())));

        foreach ($result->pruned as $item) {
            $this->line(sprintf('  - [%s] %s', $item['driver'], $item['path']));
        }

        return $result->errors === [] ? self::SUCCESS : self::FAILURE;
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

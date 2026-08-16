<?php

namespace DatabaseBackupSync\Commands;

use DatabaseBackupSync\Manager;
use Illuminate\Console\Command;

class ListBackupsCommand extends Command
{
    protected $signature = 'db:backup:list
        {--driver= : Only list this driver.}';

    protected $description = 'List stored backups across drivers';

    public function handle(Manager $manager): int
    {
        $backups = $manager->listBackups($this->option('driver'));

        foreach ($backups as $driver => $files) {
            $this->info("Driver: {$driver}");

            if ($files === []) {
                $this->line('  (no backups)');
                continue;
            }

            $rows = [];

            foreach ($files as $file) {
                $rows[] = [
                    $file->path,
                    $this->humanBytes($file->size),
                    $file->lastModified !== null ? date('Y-m-d H:i:s', $file->lastModified) : '—',
                    substr((string) $file->checksum, 0, 12).'…',
                ];
            }

            $this->table(['File', 'Size', 'Modified', 'Checksum'], $rows);
        }

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

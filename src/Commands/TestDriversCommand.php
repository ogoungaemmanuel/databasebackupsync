<?php

namespace DatabaseBackupSync\Commands;

use DatabaseBackupSync\Manager;
use Illuminate\Console\Command;

class TestDriversCommand extends Command
{
    protected $signature = 'db:backup:test
        {--driver=* : Only test these drivers. Repeatable.}';

    protected $description = 'Verify connectivity to each cloud driver with a probe upload';

    public function handle(Manager $manager): int
    {
        $drivers = $this->option('driver') !== [] ? $this->option('driver') : null;
        $results = $manager->testDrivers($drivers);

        $rows = [];
        $failed = false;

        foreach ($results as $driver => $result) {
            $rows[] = [
                $driver,
                $result['ok'] ? '✅ OK' : '❌ FAILED',
                $result['error'] ?? '—',
            ];

            if (! $result['ok']) {
                $failed = true;
            }
        }

        $this->table(['Driver', 'Status', 'Error'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

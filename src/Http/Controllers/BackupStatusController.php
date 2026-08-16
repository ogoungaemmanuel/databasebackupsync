<?php

namespace DatabaseBackupSync\Http\Controllers;

use DatabaseBackupSync\Manager;
use DatabaseBackupSync\Models\BackupRun;
use DatabaseBackupSync\Support\StorageUsage;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

class BackupStatusController extends Controller
{
    public function __invoke(Manager $manager)
    {
        $config = config('database-backup');

        $usage = [];
        foreach (array_keys($config['drivers'] ?? []) as $driverName) {
            try {
                $usage[$driverName] = StorageUsage::measure($manager->driver($driverName), $config['drivers'][$driverName]['prefix'] ?? '')->toArray();
            } catch (\Throwable $e) {
                $usage[$driverName] = ['error' => $e->getMessage()];
            }
        }

        $lastRun = null;
        $recentRuns = [];

        if (Schema::hasTable('database_backup_runs')) {
            $lastRun = BackupRun::query()->latest('id')->first();
            $recentRuns = BackupRun::query()->latest('id')->limit(10)->get()->toArray();
        }

        return response()->json([
            'service' => 'database-backup-sync',
            'time' => now()->toIso8601String(),
            'last_run' => $lastRun,
            'recent_runs' => $recentRuns,
            'storage_usage' => $usage,
            'metrics' => $manager->metrics()->snapshot(),
            'config' => [
                'default_driver' => $config['default_driver'],
                'encryption_enabled' => (bool) $config['encryption']['enabled'],
                'retention' => $config['retention'],
                'schedule' => $config['scheduling']['expression'],
            ],
        ]);
    }
}

<?php

namespace DatabaseBackupSync\Facades;

use DatabaseBackupSync\Manager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \DatabaseBackupSync\Support\BackupResult backup(array $options = [])
 * @method static \DatabaseBackupSync\Support\PruneResult prune(?array $drivers = null)
 * @method static array listBackups(?string $driver = null)
 * @method static \DatabaseBackupSync\Support\RestoreResult restore(string $remotePath, array $options = [])
 * @method static array testDrivers(?array $drivers = null)
 * @method static \DatabaseBackupSync\Drivers\DriverContract driver(string $name)
 * @method static array drivers()
 * @method static \DatabaseBackupSync\Support\Metrics metrics()
 *
 * @see \DatabaseBackupSync\Manager
 */
class DatabaseBackup extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}

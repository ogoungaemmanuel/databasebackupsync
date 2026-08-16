<?php

namespace DatabaseBackupSync\Exceptions;

use RuntimeException;

class DriverNotFoundException extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(sprintf(
            'The database backup driver [%s] is not configured. Add it to config/database-backup.php (drivers) or set DB_BACKUP_DRIVER.',
            $driver
        ));
    }
}

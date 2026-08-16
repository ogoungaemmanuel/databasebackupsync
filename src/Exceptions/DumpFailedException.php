<?php

namespace DatabaseBackupSync\Exceptions;

use RuntimeException;

class DumpFailedException extends RuntimeException
{
    public static function fromProcess(string $driver, string $command, string $output, string $errorOutput, int $exitCode): self
    {
        return new self(sprintf(
            "Database dump failed for [%s].\nCommand: %s\nExit code: %d\nOutput: %s\nError: %s",
            $driver,
            $command,
            $exitCode,
            trim($output),
            trim($errorOutput)
        ));
    }

    public static function binaryNotFound(string $driver, string $binary): self
    {
        return new self(sprintf(
            'The [%s] dump binary [%s] was not found. Install it, set database-backup.database.dump.binary_path, or enable the streaming fallback (database-backup.database.dump.streaming.enabled).',
            $driver,
            $binary
        ));
    }
}

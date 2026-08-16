<?php

namespace DatabaseBackupSync\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class BackupUploadFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $driver,
        public readonly string $path,
        public readonly Throwable $exception,
    ) {
    }
}

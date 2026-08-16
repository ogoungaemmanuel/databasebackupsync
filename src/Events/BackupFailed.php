<?php

namespace DatabaseBackupSync\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class BackupFailed
{
    use Dispatchable;

    public function __construct(
        public readonly ?string $connection,
        public readonly string $label,
        public readonly Throwable $exception,
    ) {
    }
}

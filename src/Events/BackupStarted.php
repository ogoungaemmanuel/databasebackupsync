<?php

namespace DatabaseBackupSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BackupStarted
{
    use Dispatchable;

    public function __construct(
        public readonly ?string $connection,
        public readonly string $label,
    ) {
    }
}

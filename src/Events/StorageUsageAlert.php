<?php

namespace DatabaseBackupSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StorageUsageAlert
{
    use Dispatchable;

    public function __construct(
        public readonly string $driver,
        public readonly int $totalBytes,
        public readonly int $fileCount,
        public readonly int $quotaBytes,
        public readonly float $percentUsed,
    ) {
    }
}

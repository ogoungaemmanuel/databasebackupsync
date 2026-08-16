<?php

namespace DatabaseBackupSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BackupUploaded
{
    use Dispatchable;

    public function __construct(
        public readonly string $driver,
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $checksum,
        public readonly bool $multipart,
    ) {
    }
}

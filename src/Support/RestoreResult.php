<?php

namespace DatabaseBackupSync\Support;

class RestoreResult
{
    public function __construct(
        public readonly string $remotePath,
        public readonly string $driver,
        public readonly ?string $connection,
        public readonly string $localPath,
    ) {
    }
}

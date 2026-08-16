<?php

namespace DatabaseBackupSync\Drivers\Support;

class UploadResult
{
    public function __construct(
        public readonly string $driver,
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $checksum,
        public readonly bool $multipart = false,
        public readonly ?string $versionId = null,
        public readonly int $durationMs = 0,
    ) {
    }
}

<?php

namespace DatabaseBackupSync\Drivers\Support;

class RemoteFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly ?int $lastModified = null,
        public readonly ?string $checksum = null,
        public readonly ?string $versionId = null,
    ) {
    }
}

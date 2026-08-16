<?php

namespace DatabaseBackupSync\Support;

use DatabaseBackupSync\Drivers\Support\UploadResult;

class BackupResult
{
    /**
     * @param  array<int, UploadResult>  $uploads
     */
    public function __construct(
        public readonly string $file,
        public readonly int $size,
        public readonly string $checksum,
        public readonly bool $encrypted,
        public readonly ?string $connection,
        public readonly int $durationMs,
        public readonly array $uploads,
        public readonly ?int $runId = null,
    ) {
    }

    public function uploadedTo(string $driver): bool
    {
        foreach ($this->uploads as $upload) {
            if ($upload->driver === $driver) {
                return true;
            }
        }

        return false;
    }
}

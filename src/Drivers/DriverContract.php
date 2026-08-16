<?php

namespace DatabaseBackupSync\Drivers;

use DatabaseBackupSync\Drivers\Support\RemoteFile;
use DatabaseBackupSync\Drivers\Support\UploadResult;

interface DriverContract
{
    public function name(): string;

    /**
     * Upload a local file to $remotePath. Implementations must be atomic
     * (no partially visible objects) and verify integrity where possible.
     *
     * @param  array{manifest?: array<string, mixed>, resume_state?: string, label?: string}  $options
     */
    public function upload(string $localPath, string $remotePath, array $options = []): UploadResult;

    /**
     * Download $remotePath to $localPath (atomically). Returns $localPath.
     */
    public function download(string $remotePath, string $localPath): string;

    public function delete(string $remotePath): bool;

    public function exists(string $remotePath): bool;

    /**
     * @return array<int, RemoteFile>
     */
    public function list(string $prefix = ''): array;

    public function size(string $remotePath): int;

    public function checksum(string $remotePath): ?string;

    public function rename(string $from, string $to): bool;
}

<?php

namespace DatabaseBackupSync\Support;

use DatabaseBackupSync\Drivers\DriverContract;

class StorageUsage
{
    public function __construct(
        public readonly string $driver,
        public readonly int $totalBytes,
        public readonly int $fileCount,
    ) {
    }

    public static function measure(DriverContract $driver, string $prefix = ''): self
    {
        $files = $driver->list($prefix);
        $total = array_sum(array_map(fn ($f) => $f->size, $files));

        return new self($driver->name(), $total, count($files));
    }

    public function humanBytes(): string
    {
        $bytes = $this->totalBytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * @return array{driver: string, total_bytes: int, file_count: int, human: string}
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'total_bytes' => $this->totalBytes,
            'file_count' => $this->fileCount,
            'human' => $this->humanBytes(),
        ];
    }
}

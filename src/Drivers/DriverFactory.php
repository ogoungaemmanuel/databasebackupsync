<?php

namespace DatabaseBackupSync\Drivers;

use DatabaseBackupSync\Exceptions\DriverNotFoundException;
use Illuminate\Contracts\Container\Container;

class DriverFactory
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $name, array $config): DriverContract
    {
        return match ($name) {
            'local' => new LocalDriver($name, $config),
            's3' => new S3Driver($name, $config),
            'google_drive' => new GoogleDriveDriver($name, $config),
            'onedrive' => new OneDriveDriver($name, $config),
            default => throw DriverNotFoundException::forDriver($name),
        };
    }
}

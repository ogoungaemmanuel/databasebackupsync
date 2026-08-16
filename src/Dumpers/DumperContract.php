<?php

namespace DatabaseBackupSync\Dumpers;

interface DumperContract
{
    /**
     * Dump the database into $tempDir and return the path of the dump file.
     */
    public function dump(string $tempDir): string;

    /**
     * Restore a dump file into the database.
     */
    public function restore(string $filePath): void;

    /**
     * Human-readable driver name (mysql, pgsql, sqlite, sqlsrv).
     */
    public function driverName(): string;

    /**
     * Whether the dump was gzip-compressed.
     */
    public function isGzipped(): bool;
}

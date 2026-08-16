<?php

namespace DatabaseBackupSync\Dumpers;

use DatabaseBackupSync\Exceptions\DumpFailedException;

class PostgreSqlDumper extends AbstractDumper
{
    public function driverName(): string
    {
        return 'pgsql';
    }

    public function dump(string $tempDir): string
    {
        $binary = $this->findBinary('pg_dump');

        if ($binary === null) {
            return $this->streamingFallback()->dump($tempDir);
        }

        $config = $this->connectionConfig();
        $output = $tempDir.'/dump-pgsql.sql'.($this->gzipEnabled() ? '.gz' : '');

        $command = sprintf(
            '%s --host=%s --port=%s --username=%s --dbname=%s --format=plain --no-owner --no-privileges --clean --if-exists',
            escapeshellarg($binary),
            escapeshellarg($config['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($config['port'] ?? 5432)),
            escapeshellarg($config['username'] ?? 'postgres'),
            escapeshellarg($config['database'] ?? '')
        );

        return $this->dumpViaProcess($command, $output, $this->processEnv([
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
        ]));
    }

    public function restore(string $filePath): void
    {
        $binary = $this->findBinary('psql');

        if ($binary === null) {
            throw DumpFailedException::binaryNotFound('pgsql', 'psql');
        }

        $config = $this->connectionConfig();
        $command = sprintf(
            '%s --host=%s --port=%s --username=%s --dbname=%s --set=ON_ERROR_STOP=1',
            escapeshellarg($binary),
            escapeshellarg($config['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($config['port'] ?? 5432)),
            escapeshellarg($config['username'] ?? 'postgres'),
            escapeshellarg($config['database'] ?? '')
        );

        $this->restoreViaProcess($command, $filePath, $this->processEnv([
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
        ]));
    }

    protected function streamingFallback(): StreamDumper
    {
        return new StreamDumper($this->app, $this->connection, $this->options);
    }
}

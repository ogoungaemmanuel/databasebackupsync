<?php

namespace DatabaseBackupSync\Dumpers;

use DatabaseBackupSync\Exceptions\DumpFailedException;

class MySqlDumper extends AbstractDumper
{
    public function driverName(): string
    {
        return 'mysql';
    }

    public function dump(string $tempDir): string
    {
        $binary = $this->findBinary('mysqldump');

        if ($binary === null) {
            return $this->streamingFallback()->dump($tempDir);
        }

        $config = $this->connectionConfig();
        $output = $tempDir.'/dump-mysql.sql'.($this->gzipEnabled() ? '.gz' : '');

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --quick --routines --triggers --events --no-tablespaces --hex-blob --set-gtid-purged=OFF --default-character-set=utf8mb4 %s',
            escapeshellarg($binary),
            escapeshellarg($config['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg($config['username'] ?? 'root'),
            escapeshellarg($config['database'] ?? '')
        );

        if (! empty($config['unix_socket'])) {
            $command .= sprintf(' --socket=%s', escapeshellarg($config['unix_socket']));
        }

        $command .= ' '.escapeshellarg($config['database'] ?? '');

        return $this->dumpViaProcess($command, $output, $this->processEnv([
            'MYSQL_PWD' => (string) ($config['password'] ?? ''),
        ]));
    }

    public function restore(string $filePath): void
    {
        $binary = $this->findBinary('mysql');

        if ($binary === null) {
            throw DumpFailedException::binaryNotFound('mysql', 'mysql');
        }

        $config = $this->connectionConfig();
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s %s',
            escapeshellarg($binary),
            escapeshellarg($config['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg($config['username'] ?? 'root'),
            escapeshellarg($config['database'] ?? '')
        );

        $this->restoreViaProcess($command, $filePath, $this->processEnv([
            'MYSQL_PWD' => (string) ($config['password'] ?? ''),
        ]));
    }

    protected function streamingFallback(): StreamDumper
    {
        return new StreamDumper($this->app, $this->connection, $this->options);
    }
}

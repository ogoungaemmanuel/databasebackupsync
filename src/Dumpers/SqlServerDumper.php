<?php

namespace DatabaseBackupSync\Dumpers;

use DatabaseBackupSync\Exceptions\DumpFailedException;

class SqlServerDumper extends AbstractDumper
{
    public function driverName(): string
    {
        return 'sqlsrv';
    }

    public function dump(string $tempDir): string
    {
        // BACKUP DATABASE ... TO DISK writes on the SQL Server host, not the
        // client. It is only usable when this app runs on the DB host.
        $useServerSide = (bool) ($this->options['server_side_backup'] ?? false);

        if ($useServerSide) {
            $binary = $this->findBinary('sqlcmd');

            if ($binary !== null) {
                return $this->serverSideDump($binary, $tempDir);
            }
        }

        // Default: portable client-side streaming via PDO.
        return $this->streamingFallback()->dump($tempDir);
    }

    public function restore(string $filePath): void
    {
        $binary = $this->findBinary('sqlcmd');

        if ($binary === null) {
            throw DumpFailedException::binaryNotFound('sqlsrv', 'sqlcmd');
        }

        $config = $this->connectionConfig();
        $command = sprintf(
            '%s -S %s,%s -U %s -P %s -d %s -b -i %s',
            escapeshellarg($binary),
            escapeshellarg($config['host'] ?? 'localhost'),
            escapeshellarg((string) ($config['port'] ?? 1433)),
            escapeshellarg($config['username'] ?? 'sa'),
            escapeshellarg((string) ($config['password'] ?? '')),
            escapeshellarg($config['database'] ?? ''),
            escapeshellarg($filePath)
        );

        $process = \Symfony\Component\Process\Process::fromShellCommandline($command);
        $process->setTimeout($this->timeout());
        $process->run();

        if (! $process->isSuccessful()) {
            throw DumpFailedException::fromProcess('sqlsrv', $command, $process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? -1);
        }
    }

    protected function serverSideDump(string $binary, string $tempDir): string
    {
        $config = $this->connectionConfig();
        $serverPath = $this->options['server_backup_path'] ?? '/var/opt/mssql/backup/dump-sqlsrv.bak';
        $output = $tempDir.'/dump-sqlsrv.bak';

        $command = sprintf(
            '%s -S %s,%s -U %s -P %s -Q "BACKUP DATABASE [%s] TO DISK = N\'%s\' WITH COMPRESSION, INIT, CHECKSUM"',
            escapeshellarg($binary),
            escapeshellarg($config['host'] ?? 'localhost'),
            escapeshellarg((string) ($config['port'] ?? 1433)),
            escapeshellarg($config['username'] ?? 'sa'),
            escapeshellarg((string) ($config['password'] ?? '')),
            $config['database'] ?? '',
            $serverPath
        );

        $process = \Symfony\Component\Process\Process::fromShellCommandline($command);
        $process->setTimeout($this->timeout());
        $process->run();

        if (! $process->isSuccessful()) {
            throw DumpFailedException::fromProcess('sqlsrv', $command, $process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? -1);
        }

        // Copy the .bak from the server host (same host assumption).
        if (! @copy($serverPath, $output)) {
            throw new DumpFailedException("Unable to copy server-side backup [{$serverPath}] to [{$output}].");
        }

        return $output;
    }

    protected function streamingFallback(): StreamDumper
    {
        return new StreamDumper($this->app, $this->connection, $this->options);
    }
}

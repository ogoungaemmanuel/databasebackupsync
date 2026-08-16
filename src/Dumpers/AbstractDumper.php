<?php

namespace DatabaseBackupSync\Dumpers;

use DatabaseBackupSync\Exceptions\DumpFailedException;
use DatabaseBackupSync\Support\GzipStream;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Process\Process;

abstract class AbstractDumper implements DumperContract
{
    protected ?string $connection;

    /**
     * @var array{streaming?: bool, gzip?: bool, binary_path?: string, timeout?: int, chunk_size?: int}
     */
    protected array $options;

    protected bool $gzipped = false;

    public function __construct(
        protected Container $app,
        ?string $connection = null,
        array $options = [],
    ) {
        $this->connection = $connection;
        $this->options = $options;
    }

    public function isGzipped(): bool
    {
        return $this->gzipped;
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionConfig(): array
    {
        $name = $this->connection ?? $this->app['config']->get('database.default');
        $config = $this->app['config']->get("database.connections.{$name}");

        if (! is_array($config)) {
            throw new \RuntimeException("Database connection [{$name}] is not configured.");
        }

        return $config;
    }

    protected function gzipEnabled(): bool
    {
        return (bool) ($this->options['gzip'] ?? $this->app['config']->get('database-backup.database.dump.gzip', true));
    }

    protected function timeout(): int
    {
        return (int) ($this->options['timeout'] ?? $this->app['config']->get('database-backup.database.dump.timeout', 3600));
    }

    protected function chunkSize(): int
    {
        return (int) ($this->options['chunk_size'] ?? $this->app['config']->get('database-backup.database.dump.streaming.chunk_size', 2000));
    }

    /**
     * Run a dump command, streaming stdout to a file. Uses a shell pipe to
     * gzip when the gzip binary exists; otherwise compresses with PHP zlib.
     */
    protected function dumpViaProcess(string $command, string $outputPath, array $env = []): string
    {
        $gzip = $this->gzipEnabled();
        $gzipBinary = $this->findBinary('gzip');

        if ($gzip && $gzipBinary !== null) {
            $cmd = sprintf('%s | %s -c > %s', $command, escapeshellarg($gzipBinary), escapeshellarg($outputPath));
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout($this->timeout());
            $process->setEnv($env);
            $process->run();

            if (! $process->isSuccessful()) {
                throw DumpFailedException::fromProcess($this->driverName(), $cmd, $process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? -1);
            }

            $this->gzipped = true;

            return $outputPath;
        }

        $plain = $outputPath.'.plain';
        $handle = fopen($plain, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open dump output [{$plain}].");
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout($this->timeout());
        $process->setEnv($env);
        $process->run(function ($type, $buffer) use ($handle) {
            fwrite($handle, $buffer);
        });
        fclose($handle);

        if (! $process->isSuccessful()) {
            @unlink($plain);
            throw DumpFailedException::fromProcess($this->driverName(), $command, $process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? -1);
        }

        if ($gzip) {
            GzipStream::compress($plain, $outputPath);
            @unlink($plain);
            $this->gzipped = true;

            return $outputPath;
        }

        return $plain;
    }

    /**
     * Run a restore command, streaming a (possibly gzipped) file into stdin.
     */
    protected function restoreViaProcess(string $command, string $filePath, array $env = []): void
    {
        $input = $filePath;

        if (GzipStream::isGzip($filePath)) {
            $plain = $filePath.'.plain';
            GzipStream::decompress($filePath, $plain);
            $input = $plain;
        }

        try {
            $process = Process::fromShellCommandline(sprintf('%s < %s', $command, escapeshellarg($input)));
            $process->setTimeout($this->timeout());
            $process->setEnv($env);
            $process->run();

            if (! $process->isSuccessful()) {
                throw DumpFailedException::fromProcess($this->driverName(), $command, $process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? -1);
            }
        } finally {
            if ($input !== $filePath && is_file($input)) {
                @unlink($input);
            }
        }
    }

    /**
     * Locate a binary on PATH (or return the configured path).
     */
    protected function findBinary(string $name): ?string
    {
        $configured = $this->options['binary_path'] ?? $this->app['config']->get('database-backup.database.dump.binary_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $process = Process::fromShellCommandline(sprintf('%s %s 2>nul', $which, escapeshellarg($name)));
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $path = trim(explode("\n", $process->getOutput())[0]);

        return $path === '' ? null : $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function processEnv(array $extra = []): array
    {
        return $extra;
    }
}

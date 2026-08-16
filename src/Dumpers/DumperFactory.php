<?php

namespace DatabaseBackupSync\Dumpers;

use Illuminate\Contracts\Container\Container;

class DumperFactory
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * Resolve a dumper for the given connection.
     *
     * @param  array{streaming?: bool, gzip?: bool, binary_path?: string, timeout?: int, chunk_size?: int, include_schema?: bool, include_data?: bool, server_side_backup?: bool}  $options
     */
    public function make(?string $connection, array $options = []): DumperContract
    {
        $connection = $connection ?? $this->app['config']->get('database.default');
        $driver = (string) $this->app['config']->get("database.connections.{$connection}.driver", 'mysql');

        $streaming = (bool) ($options['streaming'] ?? $this->app['config']->get('database-backup.database.dump.streaming.enabled', false));

        if ($streaming) {
            return new StreamDumper($this->app, $connection, $options);
        }

        return match ($driver) {
            'mysql' => new MySqlDumper($this->app, $connection, $options),
            'pgsql' => new PostgreSqlDumper($this->app, $connection, $options),
            'sqlite' => new SqliteDumper($this->app, $connection, $options),
            'sqlsrv' => new SqlServerDumper($this->app, $connection, $options),
            default => throw new \RuntimeException("Unsupported database driver [{$driver}] for backup. Supported: mysql, pgsql, sqlite, sqlsrv."),
        };
    }
}

<?php

namespace DatabaseBackupSync\Dumpers;

use DatabaseBackupSync\Exceptions\DumpFailedException;
use DatabaseBackupSync\Support\GzipStream;
use Illuminate\Support\Facades\DB;
use PDO;

class SqliteDumper extends AbstractDumper
{
    public function driverName(): string
    {
        return 'sqlite';
    }

    public function dump(string $tempDir): string
    {
        $config = $this->connectionConfig();
        $database = $config['database'] ?? null;

        if (! is_string($database) || ! is_file($database)) {
            throw new \RuntimeException("SQLite database file [{$database}] not found.");
        }

        $output = $tempDir.'/dump-sqlite.sql'.($this->gzipEnabled() ? '.gz' : '');
        $plain = $tempDir.'/dump-sqlite.sql';

        // VACUUM INTO produces a consistent, atomic snapshot (SQLite >= 3.27).
        $pdo = DB::connection($this->connection)->getPdo();
        $pdo->exec(sprintf("VACUUM INTO %s", $pdo->quote($plain)));

        if ($this->gzipEnabled()) {
            GzipStream::compress($plain, $output);
            @unlink($plain);
            $this->gzipped = true;

            return $output;
        }

        return $plain;
    }

    public function restore(string $filePath): void
    {
        $config = $this->connectionConfig();
        $database = $config['database'] ?? null;

        if (! is_string($database)) {
            throw new \RuntimeException('SQLite database path is not configured.');
        }

        $input = $filePath;

        if (GzipStream::isGzip($filePath)) {
            $input = $filePath.'.plain';
            GzipStream::decompress($filePath, $input);
        }

        try {
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA foreign_keys = OFF;');
            $pdo->exec('BEGIN;');

            $handle = fopen($input, 'rb');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open dump [{$input}].");
            }

            $buffer = '';
            while (! feof($handle)) {
                $buffer .= fread($handle, 1048576);
                // Execute complete statements (split on semicolons at line ends).
                while (($pos = strpos($buffer, ";\n")) !== false) {
                    $statement = substr($buffer, 0, $pos + 1);
                    $buffer = substr($buffer, $pos + 2);
                    if (trim($statement) !== '') {
                        $pdo->exec($statement);
                    }
                }
            }
            fclose($handle);

            if (trim($buffer) !== '') {
                $pdo->exec($buffer);
            }

            $pdo->exec('COMMIT;');
        } catch (\Throwable $e) {
            if (isset($pdo)) {
                $pdo->exec('ROLLBACK;');
            }
            throw new DumpFailedException('SQLite restore failed: '.$e->getMessage(), 0, $e);
        } finally {
            if ($input !== $filePath && is_file($input)) {
                @unlink($input);
            }
        }
    }
}

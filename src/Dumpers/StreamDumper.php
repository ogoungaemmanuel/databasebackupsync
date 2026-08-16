<?php

namespace DatabaseBackupSync\Dumpers;

use DatabaseBackupSync\Support\GzipStream;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;

/**
 * Portable, memory-bounded streaming dumper used when the native dump binary
 * is unavailable or --streaming is requested. Reads tables in chunks via PDO
 * and writes plain SQL. Schema is included for MySQL/SQLite; for PostgreSQL
 * and SQL Server the schema is emitted via the native tool when available
 * (pg_dump --schema-only) or skipped (data-only) otherwise.
 */
class StreamDumper extends AbstractDumper
{
    protected PDO $pdo;

    protected string $driver;

    public function driverName(): string
    {
        return $this->driver;
    }

    public function dump(string $tempDir): string
    {
        $this->pdo = DB::connection($this->connection)->getPdo();
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $output = $tempDir.'/dump-'.$this->driver.'.sql'.($this->gzipEnabled() ? '.gz' : '');
        $plain = $tempDir.'/dump-'.$this->driver.'.sql';

        $handle = fopen($plain, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open streaming dump output [{$plain}].");
        }

        try {
            $this->writeHeader($handle);

            if ($this->includeSchema()) {
                $this->dumpSchema($handle);
            }

            if ($this->includeData()) {
                $this->dumpData($handle);
            }

            fwrite($handle, "-- database-backup-sync streaming dump complete\n");
        } finally {
            fclose($handle);
        }

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
        // Delegate to the native restore path (mysql/psql/sqlite3/sqlcmd).
        $dumper = match ($this->driver) {
            'mysql' => new MySqlDumper($this->app, $this->connection, $this->options),
            'pgsql' => new PostgreSqlDumper($this->app, $this->connection, $this->options),
            'sqlite' => new SqliteDumper($this->app, $this->connection, $this->options),
            'sqlsrv' => new SqlServerDumper($this->app, $this->connection, $this->options),
            default => throw new \RuntimeException("Unsupported streaming driver [{$this->driver}]."),
        };

        $dumper->restore($filePath);
    }

    protected function includeSchema(): bool
    {
        return (bool) ($this->options['include_schema'] ?? $this->app['config']->get('database-backup.database.dump.streaming.include_schema', true));
    }

    protected function includeData(): bool
    {
        return (bool) ($this->options['include_data'] ?? $this->app['config']->get('database-backup.database.dump.streaming.include_data', true));
    }

    protected function writeHeader($handle): void
    {
        fwrite($handle, "-- database-backup-sync streaming dump\n");
        fwrite($handle, '-- driver: '.$this->driver."\n");
        fwrite($handle, '-- generated: '.date('c')."\n");
        fwrite($handle, "-- chunk size: {$this->chunkSize()} rows\n\n");

        if ($this->driver === 'mysql') {
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");
        } elseif ($this->driver === 'sqlite') {
            fwrite($handle, "PRAGMA foreign_keys = OFF;\nBEGIN;\n");
        } elseif ($this->driver === 'pgsql') {
            fwrite($handle, "SET session_replication_role = replica;\n");
        }
    }

    protected function dumpSchema($handle): void
    {
        foreach ($this->tables() as $table) {
            $schema = $this->tableSchema($table);

            if ($schema !== null) {
                fwrite($handle, $schema."\n\n");
            }
        }

        if ($this->driver === 'pgsql' && $this->includeData()) {
            // pg_dump --schema-only is the reliable way to capture PG DDL.
            $pgDump = $this->findBinary('pg_dump');
            if ($pgDump !== null) {
                $config = $this->connectionConfig();
                $command = sprintf(
                    '%s --host=%s --port=%s --username=%s --dbname=%s --schema-only --no-owner --no-privileges',
                    escapeshellarg($pgDump),
                    escapeshellarg($config['host'] ?? '127.0.0.1'),
                    escapeshellarg((string) ($config['port'] ?? 5432)),
                    escapeshellarg($config['username'] ?? 'postgres'),
                    escapeshellarg($config['database'] ?? '')
                );
                $process = \Symfony\Component\Process\Process::fromShellCommandline($command);
                $process->setTimeout($this->timeout());
                $process->setEnv(['PGPASSWORD' => (string) ($config['password'] ?? '')]);
                $process->run();
                if ($process->isSuccessful()) {
                    fwrite($handle, $process->getOutput()."\n");
                }
            }
        }
    }

    protected function dumpData($handle): void
    {
        foreach ($this->tables() as $table) {
            $this->dumpTableData($handle, $table);
        }

        if ($this->driver === 'mysql') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } elseif ($this->driver === 'sqlite') {
            fwrite($handle, "COMMIT;\nPRAGMA foreign_keys = ON;\n");
        } elseif ($this->driver === 'pgsql') {
            fwrite($handle, "SET session_replication_role = DEFAULT;\n");
        }
    }

    protected function dumpTableData($handle, string $table): void
    {
        $quoted = $this->quoteIdentifier($table);
        $columns = $this->columns($table);
        $pk = $this->primaryKey($table);
        $chunk = $this->chunkSize();

        fwrite($handle, "-- data: {$table}\n");

        if ($columns === []) {
            return;
        }

        $colList = implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), $columns));
        $offset = 0;
        $lastPk = null;
        $buffer = [];
        $bufferCount = 0;

        $flush = function () use (&$buffer, &$bufferCount, $handle, $quoted, $colList) {
            if ($bufferCount === 0) {
                return;
            }
            $values = implode(",\n", $buffer);
            fwrite($handle, "INSERT INTO {$quoted} ({$colList}) VALUES\n{$values};\n");
            $buffer = [];
            $bufferCount = 0;
        };

        do {
            $rows = $this->selectChunk($table, $columns, $pk, $offset, $chunk, $lastPk);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $buffer[] = '('.$this->rowValues($row, $columns).')';
                $bufferCount++;

                if ($bufferCount >= 200) {
                    $flush();
                }
            }

            if ($pk !== null) {
                $lastPk = $rows[count($rows) - 1][$pk];
            }

            $offset += count($rows);
        } while (count($rows) === $chunk);

        $flush();
    }

    /**
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return match ($this->driver) {
            'mysql' => array_map(fn ($r) => (string) reset($r), $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM)),
            'sqlite' => array_map(
                fn ($r) => (string) $r['name'],
                $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)
            ),
            'pgsql' => array_map(
                fn ($r) => (string) $r['table_name'],
                $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_ASSOC)
            ),
            'sqlsrv' => array_map(
                fn ($r) => (string) $r['TABLE_NAME'],
                $this->pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC)
            ),
            default => [],
        };
    }

    protected function tableSchema(string $table): ?string
    {
        return match ($this->driver) {
            'mysql' => $this->mysqlSchema($table),
            'sqlite' => $this->sqliteSchema($table),
            default => null,
        };
    }

    protected function mysqlSchema(string $table): ?string
    {
        $stmt = $this->pdo->query('SHOW CREATE TABLE '.$this->quoteIdentifier($table));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $create = (string) ($row['Create Table'] ?? '');
        $drop = 'DROP TABLE IF EXISTS '.$this->quoteIdentifier($table).";\n";

        return $drop.$create.';';
    }

    protected function sqliteSchema(string $table): ?string
    {
        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || empty($row['sql'])) {
            return null;
        }

        $out = 'DROP TABLE IF EXISTS '.$this->quoteIdentifier($table).";\n".$row['sql'].';';

        // Indexes and triggers for this table.
        $idx = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND tbl_name = ? AND sql IS NOT NULL");
        $idx->execute([$table]);
        foreach ($idx->fetchAll(PDO::FETCH_ASSOC) as $extra) {
            $out .= "\n".$extra['sql'].';';
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    protected function columns(string $table): array
    {
        return match ($this->driver) {
            'mysql' => array_map(
                fn ($r) => (string) $r['Field'],
                $this->pdo->query('SHOW COLUMNS FROM '.$this->quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC)
            ),
            'sqlite' => array_map(
                fn ($r) => (string) $r['name'],
                $this->pdo->query('PRAGMA table_info('.$this->quoteIdentifier($table).')')->fetchAll(PDO::FETCH_ASSOC)
            ),
            'pgsql' => array_map(
                fn ($r) => (string) $r['column_name'],
                $this->pdo->query(sprintf(
                    "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = %s ORDER BY ordinal_position",
                    $this->pdo->quote($table)
                ))->fetchAll(PDO::FETCH_ASSOC)
            ),
            'sqlsrv' => array_map(
                fn ($r) => (string) $r['COLUMN_NAME'],
                $this->pdo->query(sprintf(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s ORDER BY ORDINAL_POSITION",
                    $this->pdo->quote($table)
                ))->fetchAll(PDO::FETCH_ASSOC)
            ),
            default => [],
        };
    }

    protected function primaryKey(string $table): ?string
    {
        return match ($this->driver) {
            'mysql' => $this->mysqlPrimaryKey($table),
            'sqlite' => $this->sqlitePrimaryKey($table),
            'pgsql' => $this->pgsqlPrimaryKey($table),
            'sqlsrv' => $this->sqlsrvPrimaryKey($table),
            default => null,
        };
    }

    protected function mysqlPrimaryKey(string $table): ?string
    {
        $stmt = $this->pdo->query('SHOW KEYS FROM '.$this->quoteIdentifier($table)." WHERE Key_name = 'PRIMARY'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return null;
        }

        usort($rows, fn ($a, $b) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);

        return (string) $rows[0]['Column_name'];
    }

    protected function sqlitePrimaryKey(string $table): ?string
    {
        $rows = $this->pdo->query('PRAGMA table_info('.$this->quoteIdentifier($table).')')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ((int) $row['pk'] > 0) {
                return (string) $row['name'];
            }
        }

        return null;
    }

    protected function pgsqlPrimaryKey(string $table): ?string
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT kcu.column_name FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
             WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public' AND tc.table_name = %s
             ORDER BY kcu.ordinal_position LIMIT 1",
            $this->pdo->quote($table)
        ));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['column_name'];
    }

    protected function sqlsrvPrimaryKey(string $table): ?string
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT kcu.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
             JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
               ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = %s
             ORDER BY kcu.ORDINAL_POSITION",
            $this->pdo->quote($table)
        ));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['COLUMN_NAME'];
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, array<string, mixed>>
     */
    protected function selectChunk(string $table, array $columns, ?string $pk, int $offset, int $chunk, mixed &$lastPk): array
    {
        $quoted = $this->quoteIdentifier($table);
        $colList = implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), $columns));

        $sql = match ($this->driver) {
            'mysql' => $pk !== null
                ? "SELECT {$colList} FROM {$quoted} WHERE {$this->quoteIdentifier($pk)} > ? ORDER BY {$this->quoteIdentifier($pk)} LIMIT {$chunk}"
                : "SELECT {$colList} FROM {$quoted} LIMIT {$offset}, {$chunk}",
            'sqlite' => "SELECT {$colList} FROM {$quoted} LIMIT {$chunk} OFFSET {$offset}",
            'pgsql' => $pk !== null
                ? "SELECT {$colList} FROM {$quoted} WHERE {$this->quoteIdentifier($pk)} > ? ORDER BY {$this->quoteIdentifier($pk)} LIMIT {$chunk}"
                : "SELECT {$colList} FROM {$quoted} ORDER BY 1 LIMIT {$chunk} OFFSET {$offset}",
            'sqlsrv' => $pk !== null
                ? "SELECT {$colList} FROM {$quoted} ORDER BY {$this->quoteIdentifier($pk)} OFFSET {$offset} ROWS FETCH NEXT {$chunk} ROWS ONLY"
                : "SELECT {$colList} FROM {$quoted}",
            default => "SELECT {$colList} FROM {$quoted}",
        };

        $stmt = $this->pdo->prepare($sql);

        if ($pk !== null && $lastPk !== null && ($this->driver === 'mysql' || $this->driver === 'pgsql')) {
            $stmt->bindValue(1, $lastPk);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columns
     */
    protected function rowValues(array $row, array $columns): string
    {
        $values = [];

        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $values[] = $this->quoteValue($value);
        }

        return implode(', ', $values);
    }

    protected function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($this->driver === 'pgsql' && is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if ($this->driver === 'pgsql' && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', (string) $value)) {
            // Binary-safe bytea literal for PostgreSQL.
            return "'\\\\x".bin2hex((string) $value)."'";
        }

        return $this->pdo->quote((string) $value);
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return match ($this->driver) {
            'mysql' => '`'.str_replace('`', '``', $identifier).'`',
            'sqlite', 'pgsql' => '"'.str_replace('"', '""', $identifier).'"',
            'sqlsrv' => '['.str_replace(']', ']]', $identifier).']',
            default => $identifier,
        };
    }
}

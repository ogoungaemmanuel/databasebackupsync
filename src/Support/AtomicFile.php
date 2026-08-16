<?php

namespace DatabaseBackupSync\Support;

use RuntimeException;

class AtomicFile
{
    /**
     * Write content to a temp file in the same directory, fsync, then rename
     * over the destination. The rename is atomic on POSIX and Windows NTFS.
     */
    public static function write(string $path, string $content, int $permissions = 0640): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create directory [{$dir}].");
        }

        $tmp = $dir.'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open temp file [{$tmp}].");
        }

        try {
            fwrite($handle, $content);
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        @chmod($tmp, $permissions);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Atomic rename failed for [{$path}].");
        }
    }

    /**
     * Atomically move a file (e.g. a completed download) into place.
     */
    public static function move(string $from, string $to): void
    {
        $dir = dirname($to);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create directory [{$dir}].");
        }

        if (! @rename($from, $to)) {
            if (! @copy($from, $to)) {
                throw new RuntimeException("Atomic move failed from [{$from}] to [{$to}].");
            }
            @unlink($from);
        }
    }
}

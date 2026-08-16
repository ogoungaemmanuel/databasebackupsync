<?php

namespace DatabaseBackupSync\Support;

use RuntimeException;

class Checksum
{
    /**
     * SHA-256 of a file, streamed in chunks to bound memory usage.
     */
    public static function hashFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("Cannot checksum missing file [{$path}].");
        }

        $ctx = hash_init('sha256');
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open file [{$path}] for checksum.");
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException("Read error while checksumming [{$path}].");
                }
                hash_update($ctx, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return hash_final($ctx);
    }

    /**
     * SHA-256 of a stream resource (does not rewind).
     */
    public static function hashStream($stream): string
    {
        $ctx = hash_init('sha256');

        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                throw new RuntimeException('Read error while checksumming stream.');
            }
            hash_update($ctx, $chunk);
        }

        return hash_final($ctx);
    }

    /**
     * Constant-time comparison of two hex digests.
     */
    public static function verify(string $expected, string $actual): bool
    {
        return hash_equals(strtolower($expected), strtolower($actual));
    }
}

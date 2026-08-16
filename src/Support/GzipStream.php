<?php

namespace DatabaseBackupSync\Support;

use RuntimeException;

class GzipStream
{
    /**
     * Gzip-compress a file in streaming fashion using PHP's zlib (no external
     * binary required). Returns the output path.
     */
    public static function compress(string $inputPath, string $outputPath): string
    {
        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to open files for gzip compression.');
        }

        $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Read error during gzip compression.');
                }
                $compressed = deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
                if ($compressed !== false) {
                    fwrite($out, $compressed);
                }
            }

            $tail = deflate_add($ctx, '', ZLIB_FINISH);
            if ($tail !== false) {
                fwrite($out, $tail);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $outputPath;
    }

    /**
     * Gunzip a file in streaming fashion. Returns the output path.
     */
    public static function decompress(string $inputPath, string $outputPath): string
    {
        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to open files for gunzip.');
        }

        $ctx = inflate_init(ZLIB_ENCODING_GZIP);

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Read error during gunzip.');
                }
                $plain = inflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
                if ($plain !== false) {
                    fwrite($out, $plain);
                }
            }

            $tail = inflate_add($ctx, '', ZLIB_FINISH);
            if ($tail !== false) {
                fwrite($out, $tail);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $outputPath;
    }

    public static function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }
}

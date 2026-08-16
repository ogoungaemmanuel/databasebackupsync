<?php

namespace DatabaseBackupSync\Encryption;

use DatabaseBackupSync\Exceptions\EncryptionException;
use RuntimeException;

/**
 * Streaming authenticated encryption.
 *
 * Format (all integers big-endian):
 *   magic      "DBSYNC1"           7 bytes
 *   version    0x01                1 byte
 *   cipher     0x01 (aes-256-gcm)  1 byte
 *   chunk_size uint32              4 bytes  (plaintext bytes per chunk)
 *   chunk_count uint32             4 bytes
 *   per chunk: nonce(12) + ct_len(uint32) + ciphertext + tag(16)
 *   hmac       sha256              32 bytes over everything before it
 *
 * Keys are derived from the master key with HKDF-SHA256 (separate enc/MAC
 * keys). Memory is bounded: the file is processed in chunks, so multi-GB
 * dumps encrypt with constant memory.
 */
class Encryptor
{
    public const MAGIC = 'DBSYNC1';

    public const VERSION = 1;

    public const CIPHER_AES_256_GCM = 1;

    protected string $key;

    protected int $chunkSize;

    public function __construct(string $key, int $chunkSize = 1048576)
    {
        $decoded = base64_decode($key, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw EncryptionException::invalidKey();
        }

        $this->key = $decoded;
        $this->chunkSize = max(4096, $chunkSize);
    }

    public function encrypt(string $inputPath, string $outputPath): string
    {
        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Unable to open files for encryption [{$inputPath}] -> [{$outputPath}].");
        }

        $encKey = $this->deriveKey('dbsync:v1:enc');
        $chunks = 0;

        try {
            fwrite($out, self::MAGIC);
            fwrite($out, chr(self::VERSION));
            fwrite($out, chr(self::CIPHER_AES_256_GCM));
            fwrite($out, pack('N', $this->chunkSize));
            fwrite($out, pack('N', 0)); // chunk_count placeholder

            while (! feof($in)) {
                $plain = fread($in, $this->chunkSize);

                if ($plain === false) {
                    throw new RuntimeException('Read error during encryption.');
                }

                if ($plain === '') {
                    break;
                }

                $nonce = random_bytes(12);
                $tag = '';
                $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $nonce, $tag);

                if ($ciphertext === false) {
                    throw new RuntimeException('openssl_encrypt failed during encryption.');
                }

                fwrite($out, $nonce);
                fwrite($out, pack('N', strlen($ciphertext)));
                fwrite($out, $ciphertext);
                fwrite($out, $tag);
                $chunks++;
            }

            // Patch chunk_count.
            fseek($out, strlen(self::MAGIC) + 1 + 1 + 4);
            fwrite($out, pack('N', $chunks));
            fflush($out);
        } finally {
            fclose($in);
            fclose($out);
        }

        // HMAC over the final file content (header + chunks).
        $hmac = $this->hmacFile($outputPath);
        $handle = fopen($outputPath, 'ab');
        fwrite($handle, $hmac);
        fclose($handle);

        return $outputPath;
    }

    public function decrypt(string $inputPath, string $outputPath): string
    {
        $handle = fopen($inputPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open encrypted file [{$inputPath}].");
        }

        try {
            $header = fread($handle, strlen(self::MAGIC) + 1 + 1 + 4 + 4);

            if (strlen($header) < strlen(self::MAGIC) + 1 + 1 + 4 + 4) {
                throw EncryptionException::unsupportedFormat(substr($header, 0, 7) ?: '');
            }

            $magic = substr($header, 0, 7);
            $version = ord($header[7]);
            $cipher = ord($header[8]);
            $chunkSize = unpack('N', substr($header, 9, 4))[1];
            $chunkCount = unpack('N', substr($header, 13, 4))[1];

            if ($magic !== self::MAGIC) {
                throw EncryptionException::unsupportedFormat($magic);
            }

            if ($version !== self::VERSION || $cipher !== self::CIPHER_AES_256_GCM) {
                throw EncryptionException::unsupportedFormat($magic);
            }

            // Verify HMAC over everything except the trailing 32 bytes.
            $fileSize = filesize($inputPath);
            $payloadLength = $fileSize - 32;

            if ($payloadLength < strlen($header)) {
                throw EncryptionException::integrityCheckFailed();
            }

            $macKey = $this->deriveKey('dbsync:v1:mac');
            $ctx = hash_init('sha256', HASH_HMAC, $macKey);

            rewind($handle);
            $remaining = $payloadLength;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1048576, $remaining));
                if ($chunk === false) {
                    throw new RuntimeException('Read error during HMAC verification.');
                }
                hash_update($ctx, $chunk);
                $remaining -= strlen($chunk);
            }

            $storedHmac = fread($handle, 32);
            if (! hash_equals(hash_final($ctx), $storedHmac)) {
                throw EncryptionException::integrityCheckFailed();
            }

            // Decrypt chunks.
            $encKey = $this->deriveKey('dbsync:v1:enc');
            $out = fopen($outputPath, 'wb');

            if ($out === false) {
                throw new RuntimeException("Unable to open output file [{$outputPath}].");
            }

            try {
                rewind($handle);
                fseek($handle, strlen($header));

                for ($i = 0; $i < $chunkCount; $i++) {
                    $nonce = fread($handle, 12);
                    $lenRaw = fread($handle, 4);

                    if (strlen($nonce) !== 12 || strlen($lenRaw) !== 4) {
                        throw EncryptionException::integrityCheckFailed();
                    }

                    $len = unpack('N', $lenRaw)[1];
                    $ciphertext = fread($handle, $len);
                    $tag = fread($handle, 16);

                    if (strlen($ciphertext) !== $len || strlen($tag) !== 16) {
                        throw EncryptionException::integrityCheckFailed();
                    }

                    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $nonce, $tag);

                    if ($plain === false) {
                        throw EncryptionException::integrityCheckFailed();
                    }

                    fwrite($out, $plain);
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($handle);
        }

        return $outputPath;
    }

    public static function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    protected function deriveKey(string $info): string
    {
        return hash_hkdf('sha256', $this->key, 32, $info);
    }

    protected function hmacFile(string $path): string
    {
        $macKey = $this->deriveKey('dbsync:v1:mac');
        $ctx = hash_init('sha256', HASH_HMAC, $macKey);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open [{$path}] for HMAC.");
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Read error during HMAC.');
                }
                hash_update($ctx, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return hash_final($ctx);
    }
}

<?php

namespace DatabaseBackupSync\Exceptions;

use RuntimeException;

class EncryptionException extends RuntimeException
{
    public static function missingKey(): self
    {
        return new self('Encryption is enabled but no key is configured. Set DB_BACKUP_KEY to a base64-encoded 32-byte value (php -r "echo base64_encode(random_bytes(32));").');
    }

    public static function invalidKey(): self
    {
        return new self('The configured DB_BACKUP_KEY is invalid. It must be a base64-encoded 32-byte value.');
    }

    public static function integrityCheckFailed(): self
    {
        return new self('Decryption failed: HMAC verification failed. The file is corrupt or the key is wrong.');
    }

    public static function unsupportedFormat(string $magic): self
    {
        return new self(sprintf('Unsupported encrypted file format (magic [%s]). This file was not produced by this package.', $magic));
    }
}

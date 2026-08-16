<?php

namespace DatabaseBackupSync\Exceptions;

use RuntimeException;
use Throwable;

class UploadFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $driver,
        public readonly string $remotePath,
        public readonly int $attempts = 1,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromThrowable(string $driver, string $remotePath, Throwable $e, int $attempts = 1): self
    {
        return new self(
            sprintf('Upload to [%s] failed for [%s] after %d attempt(s): %s', $driver, $remotePath, $attempts, $e->getMessage()),
            $driver,
            $remotePath,
            $attempts,
            $e
        );
    }
}

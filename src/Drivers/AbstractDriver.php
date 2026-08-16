<?php

namespace DatabaseBackupSync\Drivers;

use DatabaseBackupSync\Support\RateLimiter;
use DatabaseBackupSync\Support\RetryPolicy;
use Throwable;

abstract class AbstractDriver implements DriverContract
{
    protected ?float $lastCall = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $name,
        protected array $config,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Token-bucket throttle for this driver's configured requests-per-second.
     */
    protected function throttle(): void
    {
        RateLimiter::throttle((int) $this->config('requests_per_second', 0), $this->lastCall);
    }

    /**
     * Run an operation with exponential backoff + jitter on failure.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    protected function retry(callable $fn, int $maxAttempts = 5, int $baseMs = 1000, int $maxMs = 60000): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $fn();
            } catch (Throwable $e) {
                if (! RetryPolicy::canRetry($attempt, $maxAttempts)) {
                    throw $e;
                }

                usleep(RetryPolicy::delay($attempt, $baseMs, $maxMs) * 1000);
            }
        }
    }

    /**
     * Persist the backup manifest as a sidecar file next to the backup.
     *
     * @param  array<string, mixed>  $manifest
     */
    abstract protected function storeManifest(string $remotePath, array $manifest): void;
}

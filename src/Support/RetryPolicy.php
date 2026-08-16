<?php

namespace DatabaseBackupSync\Support;

class RetryPolicy
{
    /**
     * Exponential backoff with full jitter (AWS "Decorrelated Jitter" style).
     *
     * @return int milliseconds to sleep before the given attempt (1-based)
     */
    public static function delay(int $attempt, int $baseMs = 1000, int $maxMs = 60000, bool $jitter = true): int
    {
        $attempt = max(1, $attempt);
        $exp = min($baseMs * (2 ** ($attempt - 1)), $maxMs);

        if (! $jitter) {
            return $exp;
        }

        return random_int(0, max(1, $exp));
    }

    /**
     * Whether another attempt is allowed.
     */
    public static function canRetry(int $attempt, int $maxAttempts): bool
    {
        return $attempt < $maxAttempts;
    }

    /**
     * Parse a Retry-After header (seconds or HTTP date) into seconds.
     */
    public static function retryAfterSeconds(?string $header, int $default = 5): int
    {
        if ($header === null || trim($header) === '') {
            return $default;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            return max(0, (int) $header);
        }

        $ts = strtotime($header);

        return $ts !== false ? max(0, $ts - time()) : $default;
    }
}

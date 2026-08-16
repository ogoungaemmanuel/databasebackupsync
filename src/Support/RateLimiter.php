<?php

namespace DatabaseBackupSync\Support;

class RateLimiter
{
    /**
     * Simple token-bucket throttle. Sleeps so that at most $perSecond
     * operations per second are issued. Returns the number of ms slept.
     */
    public static function throttle(int $perSecond, ?float &$lastCall = null): int
    {
        if ($perSecond <= 0) {
            return 0;
        }

        $interval = 1.0 / $perSecond;
        $now = microtime(true);

        if ($lastCall === null) {
            $lastCall = $now;

            return 0;
        }

        $elapsed = $now - $lastCall;
        $sleep = (int) (($interval - $elapsed) * 1000);

        if ($sleep > 0) {
            usleep($sleep * 1000);
            $lastCall = microtime(true);

            return $sleep;
        }

        $lastCall = $now;

        return 0;
    }
}

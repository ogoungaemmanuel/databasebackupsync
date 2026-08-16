<?php

namespace DatabaseBackupSync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the status endpoint with a shared bearer token.
 *
 * Accepts the token via the X-Backup-Token header or the ?token= query
 * parameter. Compare is constant-time. When no token is configured the
 * endpoint is disabled (403).
 */
class VerifyBackupToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('database-backup.status.token', '');

        if ($expected === '') {
            abort(403, 'Backup status endpoint is not configured with a token.');
        }

        $provided = (string) $request->header('X-Backup-Token', $request->query('token', ''));

        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid backup status token.');
        }

        return $next($request);
    }
}

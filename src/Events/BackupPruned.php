<?php

namespace DatabaseBackupSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BackupPruned
{
    use Dispatchable;

    /**
     * @param  array<int, array{driver: string, path: string, size: int}>  $pruned
     */
    public function __construct(public readonly array $pruned)
    {
    }
}

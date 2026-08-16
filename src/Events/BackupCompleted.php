<?php

namespace DatabaseBackupSync\Events;

use DatabaseBackupSync\Support\BackupResult;
use Illuminate\Foundation\Events\Dispatchable;

class BackupCompleted
{
    use Dispatchable;

    public function __construct(public readonly BackupResult $result)
    {
    }
}

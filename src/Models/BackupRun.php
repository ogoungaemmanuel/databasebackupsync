<?php

namespace DatabaseBackupSync\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    protected $table = 'database_backup_runs';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'duration_ms' => 'integer',
        'drivers' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}

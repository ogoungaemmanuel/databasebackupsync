<?php

use DatabaseBackupSync\Http\Controllers\BackupStatusController;
use DatabaseBackupSync\Http\Middleware\VerifyBackupToken;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('database-backup.status.prefix', 'database-backup'), '/');
$middleware = array_merge(
    (array) config('database-backup.status.middleware', ['api']),
    [VerifyBackupToken::class]
);

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function () {
        Route::get('status', BackupStatusController::class)->name('database-backup.status');
    });

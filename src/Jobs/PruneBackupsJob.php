<?php

namespace DatabaseBackupSync\Jobs;

use DatabaseBackupSync\Manager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneBackupsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>|null  $drivers
     */
    public function __construct(public ?array $drivers = null)
    {
    }

    public function handle(Manager $manager): void
    {
        $manager->prune($this->drivers);
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function tags(): array
    {
        return ['database-backup', 'prune'];
    }
}

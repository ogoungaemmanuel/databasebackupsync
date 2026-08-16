<?php

namespace DatabaseBackupSync\Jobs;

use DatabaseBackupSync\Manager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(public array $options = [])
    {
    }

    public function handle(Manager $manager): void
    {
        $manager->backup($this->options);
    }

    /**
     * Exponential backoff between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('database-backup.queue.backoff', [10, 30, 60, 120, 300]);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function tags(): array
    {
        return ['database-backup', 'run'];
    }
}

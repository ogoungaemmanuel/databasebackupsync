<?php

namespace DatabaseBackupSync\Support;

use DatabaseBackupSync\Drivers\Support\RemoteFile;

class RetentionPolicy
{
    protected int $days;

    protected int $count;

    protected int $maxTotalSize;

    /**
     * @param  array{days?: int, count?: int, max_total_size?: int}  $config
     */
    public function __construct(array $config)
    {
        $this->days = (int) ($config['days'] ?? 0);
        $this->count = (int) ($config['count'] ?? 0);
        $this->maxTotalSize = (int) ($config['max_total_size'] ?? 0);
    }

    /**
     * Given a driver's file listing (newest first), return the files that
     * should be pruned. Policies are ANDed: a file is pruned when it violates
     * any enabled policy.
     *
     * @param  array<int, RemoteFile>  $files
     * @return array<int, RemoteFile>
     */
    public function selectForPruning(array $files): array
    {
        // Sort newest first by lastModified (fall back to name).
        usort($files, function (RemoteFile $a, RemoteFile $b) {
            $ta = $a->lastModified ?? strtotime($a->path);
            $tb = $b->lastModified ?? strtotime($b->path);

            return $tb <=> $ta;
        });

        $prune = [];
        $seen = [];

        foreach ($files as $index => $file) {
            $reasons = [];

            // Age policy.
            if ($this->days > 0) {
                $age = $this->ageInDays($file);
                if ($age > $this->days) {
                    $reasons[] = "older than {$this->days} days";
                }
            }

            // Count policy (keep the newest N).
            if ($this->count > 0 && $index >= $this->count) {
                $reasons[] = "beyond newest {$this->count}";
            }

            // Size policy: keep newest files until the total budget is used.
            if ($this->maxTotalSize > 0) {
                $seen[] = $file;
                $total = array_sum(array_map(fn (RemoteFile $f) => $f->size, $seen));
                if ($total > $this->maxTotalSize) {
                    $reasons[] = 'total size budget exceeded';
                }
            }

            if ($reasons !== []) {
                $prune[] = $file;
            }
        }

        return $prune;
    }

    protected function ageInDays(RemoteFile $file): float
    {
        $ts = $file->lastModified;

        if ($ts === null) {
            // Fall back to parsing the filename timestamp (backup-conn-2026-08-16_02-00-00-xxxx.sql).
            if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})/', $file->path, $m)) {
                $ts = strtotime(str_replace('-', ':', $m[1].'_'.$m[2]));
            }
        }

        if ($ts === null) {
            return PHP_FLOAT_MAX; // unknown age → prune (conservative)
        }

        return (time() - $ts) / 86400;
    }
}

<?php

namespace DatabaseBackupSync\Tests\Unit;

use DatabaseBackupSync\Drivers\Support\RemoteFile;
use DatabaseBackupSync\Support\RetentionPolicy;
use DatabaseBackupSync\Tests\TestCase;

class RetentionPolicyTest extends TestCase
{
    protected function file(string $name, int $size, ?int $modified): RemoteFile
    {
        return new RemoteFile($name, $size, $modified);
    }

    public function test_count_policy_keeps_newest(): void
    {
        $policy = new RetentionPolicy(['count' => 3, 'days' => 0, 'max_total_size' => 0]);
        $files = [
            $this->file('a.sql', 1, time() - 100),
            $this->file('b.sql', 1, time() - 200),
            $this->file('c.sql', 1, time() - 300),
            $this->file('d.sql', 1, time() - 400),
        ];

        $prune = $policy->selectForPruning($files);

        $this->assertCount(1, $prune);
        $this->assertSame('d.sql', $prune[0]->path);
    }

    public function test_days_policy_prunes_old_files(): void
    {
        $policy = new RetentionPolicy(['count' => 0, 'days' => 7, 'max_total_size' => 0]);
        $files = [
            $this->file('recent.sql', 1, time() - 3600),
            $this->file('old.sql', 1, time() - 8 * 86400),
            $this->file('ancient.sql', 1, time() - 30 * 86400),
        ];

        $prune = $policy->selectForPruning($files);

        $this->assertCount(2, $prune);
        $this->assertSame(['old.sql', 'ancient.sql'], array_column($prune, 'path'));
    }

    public function test_size_policy_prunes_oldest_first(): void
    {
        $policy = new RetentionPolicy(['count' => 0, 'days' => 0, 'max_total_size' => 100]);
        $files = [
            $this->file('a.sql', 60, time() - 100),
            $this->file('b.sql', 60, time() - 200),
            $this->file('c.sql', 60, time() - 300),
        ];

        $prune = $policy->selectForPruning($files);

        // Newest (a) kept; b fits (120 > 100 → prune); c pruned.
        $this->assertCount(2, $prune);
        $this->assertSame(['b.sql', 'c.sql'], array_column($prune, 'path'));
    }

    public function test_filename_timestamp_fallback(): void
    {
        $policy = new RetentionPolicy(['count' => 0, 'days' => 1, 'max_total_size' => 0]);
        $files = [
            $this->file('backup-mysql-2020-01-01_00-00-00-abc123.sql', 1, null),
            $this->file('backup-mysql-'.date('Y-m-d').'_00-00-00-abc123.sql', 1, null),
        ];

        $prune = $policy->selectForPruning($files);

        $this->assertCount(1, $prune);
        $this->assertStringContainsString('2020-01-01', $prune[0]->path);
    }

    public function test_no_policies_keeps_everything(): void
    {
        $policy = new RetentionPolicy(['count' => 0, 'days' => 0, 'max_total_size' => 0]);
        $files = [$this->file('a.sql', 1, time() - 999999)];

        $this->assertSame([], $policy->selectForPruning($files));
    }
}
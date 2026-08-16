<?php

namespace DatabaseBackupSync\Tests;

use DatabaseBackupSync\DatabaseBackupServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = __DIR__.'/tmp/'.uniqid('test-', true);
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [DatabaseBackupServiceProvider::class];
    }

    /**
     * Testbench 5–7 hook.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $this->configureEnvironment($app);
    }

    /**
     * Testbench 8+ hook.
     */
    protected function defineEnvironment($app): void
    {
        $this->configureEnvironment($app);
    }

    protected function configureEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->tmpDir.'/test.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('database-backup.default_driver', 'local');
        $app['config']->set('database-backup.drivers.local', [
            'root' => $this->tmpDir.'/backups',
            'permissions' => 0640,
        ]);
        $app['config']->set('database-backup.temp_path', $this->tmpDir.'/tmp');
        $app['config']->set('database-backup.encryption', [
            'enabled' => false,
            'key' => base64_encode(str_repeat('k', 32)),
            'cipher' => 'aes-256-gcm',
            'chunk_size' => 1048576,
            'gpg' => ['enabled' => false],
        ]);
        $app['config']->set('database-backup.retention', [
            'enabled' => true,
            'days' => 14,
            'count' => 30,
            'max_total_size' => 0,
            'prune_on_backup' => false,
        ]);
        $app['config']->set('database-backup.scheduling.enabled', false);
        $app['config']->set('database-backup.status', [
            'enabled' => false,
            'token' => 'test-token',
            'prefix' => 'database-backup',
            'middleware' => [],
        ]);
        $app['config']->set('database-backup.notifications', [
            'on_success' => false,
            'on_failure' => false,
            'channels' => [],
        ]);
        $app['config']->set('database-backup.history.enabled', true);
    }

    protected function createTestTable(): void
    {
        Schema::connection('testing')->create('widgets', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('quantity')->default(0);
            $table->timestamps();
        });

        \Illuminate\Support\Facades\DB::connection('testing')->table('widgets')->insert([
            ['name' => 'alpha', 'quantity' => 3],
            ['name' => 'beta', 'quantity' => 7],
            ['name' => 'gamma', 'quantity' => 11],
        ]);
    }

    protected function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
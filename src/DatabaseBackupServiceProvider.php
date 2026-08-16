<?php

namespace DatabaseBackupSync;

use DatabaseBackupSync\Commands\BackupCommand;
use DatabaseBackupSync\Commands\ListBackupsCommand;
use DatabaseBackupSync\Commands\PruneCommand;
use DatabaseBackupSync\Commands\RestoreCommand;
use DatabaseBackupSync\Commands\TestDriversCommand;
use DatabaseBackupSync\Drivers\DriverFactory;
use DatabaseBackupSync\Dumpers\DumperFactory;
use DatabaseBackupSync\Encryption\EncryptionManager;
use DatabaseBackupSync\Notifications\Listeners\BackupNotificationListener;
use DatabaseBackupSync\Notifications\Notifier;
use DatabaseBackupSync\Support\BackupRunRecorder;
use DatabaseBackupSync\Support\Metrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class DatabaseBackupServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/database-backup.php', 'database-backup');

        $this->app->singleton(Manager::class, function (Container $app) {
            return new Manager($app);
        });

        $this->app->singleton(DriverFactory::class, function (Container $app) {
            return new DriverFactory($app);
        });

        $this->app->singleton(DumperFactory::class, function (Container $app) {
            return new DumperFactory($app);
        });

        $this->app->singleton(EncryptionManager::class, function (Container $app) {
            return new EncryptionManager($app);
        });

        $this->app->singleton(Metrics::class, function () {
            return new Metrics();
        });

        $this->app->singleton(BackupRunRecorder::class, function (Container $app) {
            return new BackupRunRecorder($app);
        });

        $this->app->singleton(Notifier::class, function (Container $app) {
            return new Notifier($app);
        });

        $this->app->alias(Manager::class, 'database-backup');
    }

    /**
     * Boot package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/database-backup.php' => config_path('database-backup.php'),
            ], 'database-backup-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'database-backup-migrations');

            $this->commands([
                BackupCommand::class,
                PruneCommand::class,
                ListBackupsCommand::class,
                RestoreCommand::class,
                TestDriversCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'database-backup');

        $this->registerScheduler();
        $this->registerRoutes();
        $this->registerEventListeners();
    }

    /**
     * Register the db:backup command with Laravel's scheduler.
     */
    protected function registerScheduler(): void
    {
        $config = $this->app['config'];

        if (! $config->get('database-backup.scheduling.enabled', true)) {
            return;
        }

        $this->app->booted(function () use ($config) {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $event = $schedule->command('db:backup')
                ->cron((string) $config->get('database-backup.scheduling.expression', '0 2 * * *'))
                ->timezone((string) $config->get('database-backup.scheduling.timezone', $config->get('app.timezone', 'UTC')))
                ->appendOutputTo((string) $config->get('database-backup.scheduling.log_output', storage_path('logs/database-backup-schedule.log')));

            if ($config->get('database-backup.scheduling.on_one_server', true)) {
                $event->onOneServer();
            }

            if ($config->get('database-backup.scheduling.without_overlapping', true)) {
                $event->withoutOverlapping((int) $config->get('database-backup.scheduling.expires_at', 1440));
            }
        });
    }

    /**
     * Register the status endpoint when enabled.
     */
    protected function registerRoutes(): void
    {
        if (! $this->app['config']->get('database-backup.status.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/Routes/routes.php');
    }

    /**
     * Wire event listeners (notifications, metrics, history).
     */
    protected function registerEventListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        $events->listen(
            [
                \DatabaseBackupSync\Events\BackupCompleted::class,
                \DatabaseBackupSync\Events\BackupFailed::class,
                \DatabaseBackupSync\Events\BackupUploaded::class,
                \DatabaseBackupSync\Events\BackupUploadFailed::class,
                \DatabaseBackupSync\Events\BackupPruned::class,
                \DatabaseBackupSync\Events\StorageUsageAlert::class,
            ],
            BackupNotificationListener::class
        );
    }
}

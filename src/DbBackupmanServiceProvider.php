<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman;

use Illuminate\Support\ServiceProvider;
use Tekkenking\Dbbackupman\Console\DbBackupCommand;
use Tekkenking\Dbbackupman\Contracts\StateRepository;
use Tekkenking\Dbbackupman\Contracts\Uploader;
use Tekkenking\Dbbackupman\Services\State\DiskStateRepository;
use Tekkenking\Dbbackupman\Services\Uploader\StorageUploader;
use Tekkenking\Dbbackupman\Support\DbBackupmanManager;

class DbBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dbbackup.php', 'dbbackup');

        $this->app->bind(Uploader::class, StorageUploader::class);
        $this->app->bind(StateRepository::class, DiskStateRepository::class);

        // NEW: Facade accessor binding
        $this->app->singleton('dbbackupman.manager', fn () => new DbBackupmanManager());
        $this->app->alias('dbbackupman.manager', DbBackupmanManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/dbbackup.php' => config_path('dbbackup.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([DbBackupCommand::class]);
        }
    }
}

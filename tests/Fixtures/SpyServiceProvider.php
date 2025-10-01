<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Tekkenking\Dbbackupman\Support\DbBackupmanManager;

class SpyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the facade accessor the same way the package does
        $this->app->singleton('dbbackupman.manager', fn () => new DbBackupmanManager());
        $this->app->alias('dbbackupman.manager', DbBackupmanManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SpyBackupCommand::class]); // register our spy command as db:backup
        }
    }
}

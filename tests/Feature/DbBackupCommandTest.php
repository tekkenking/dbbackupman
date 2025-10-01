<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Tekkenking\Dbbackupman\DbBackupServiceProvider;

class DbBackupCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [DbBackupServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testingpg');
        $app['config']->set('database.connections.testingpg', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'demo',
            'username' => 'user',
            'password' => 'pass',
        ]);
        Storage::fake('local');
    }

    public function test_command_wires_up_and_shows_help(): void
    {
        $this->artisan('db:backup --help')->assertExitCode(0);
    }
}

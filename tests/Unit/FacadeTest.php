<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Tekkenking\Dbbackupman\DbBackupServiceProvider;
use Tekkenking\Dbbackupman\Facades\DbBackupman;

class FacadeTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [DbBackupServiceProvider::class];
    }

    public function test_facade_runs_artisan_with_flags(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('db:backup', \Mockery::on(function ($args) {
                return isset($args['--connection'])
                    && $args['--connection'] === 'pgsql'
                    && isset($args['--mode'])
                    && $args['--mode'] === 'full'
                    && array_key_exists('--gzip', $args); // boolean flag present
            }))
            ->andReturn(0);

        $rc = DbBackupman::run([
            'connection' => 'pgsql',
            'mode'       => 'full',
            'gzip'       => true,
        ]);

        $this->assertSame(0, $rc);
    }
}

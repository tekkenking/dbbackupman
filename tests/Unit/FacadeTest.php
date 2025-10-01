<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Tekkenking\Dbbackupman\Facades\DbBackupman;
use Tekkenking\Dbbackupman\Tests\Fixtures\SpyBackupCommand;
use Tekkenking\Dbbackupman\Tests\Fixtures\SpyServiceProvider;

class FacadeTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        // IMPORTANT: Do NOT load the real package provider here,
        // we want our test-only provider to register the spy command
        return [SpyServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        SpyBackupCommand::$captured = null;
    }

    public function test_facade_runs_artisan_with_flags(): void
    {
        $rc = DbBackupman::run([
            'connection' => 'pgsql',
            'mode'       => 'full',
            'gzip'       => true,
        ]);

        $this->assertSame(0, $rc);
        $this->assertIsArray(SpyBackupCommand::$captured);

        $this->assertSame('pgsql', SpyBackupCommand::$captured['connection']);
        $this->assertSame('full',  SpyBackupCommand::$captured['mode']);
        $this->assertTrue(SpyBackupCommand::$captured['gzip']);
    }
}

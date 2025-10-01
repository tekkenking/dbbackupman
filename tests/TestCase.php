<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tekkenking\Dbbackupman\DbBackupServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [DbBackupServiceProvider::class];
    }
}

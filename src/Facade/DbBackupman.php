<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Facades;

use Illuminate\Support\Facades\Facade;

class DbBackupman extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'dbbackupman.manager';
    }
}

<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Fixtures;

use Illuminate\Console\Command;

class SpyBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--connection=}
        {--mode=}
        {--gzip : gzip outputs}';

    /** @var array<string,mixed>|null */
    public static ?array $captured = null;

    public function handle(): int
    {
        self::$captured = [
            'connection' => $this->option('connection'),
            'mode'       => $this->option('mode'),
            'gzip'       => (bool) $this->option('gzip'),
        ];
        return self::SUCCESS;
    }
}

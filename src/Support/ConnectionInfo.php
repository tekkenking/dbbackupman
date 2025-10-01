<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

final class ConnectionInfo
{
    public function __construct(
        public readonly string $driver,     // 'pgsql' | 'mysql'
        public readonly string $host,
        public readonly int    $port,
        public readonly string $database,
        public readonly string $username,
        public readonly ?string $password,
        public readonly array  $tools,      // keys: pg_dump, pg_dumpall, psql, mysqldump, mysqlbinlog
        public readonly string $workdir,    // output dir
        public readonly string $timestamp,  // Ymd_His
        public readonly ?string $noteTag    // e.g. "_note" or null
    ) {}
}

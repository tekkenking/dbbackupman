<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Dump;

use Tekkenking\Dbbackupman\Contracts\Dumper;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;

class MySqlDumper implements Dumper
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function dump(ConnectionInfo $c, array $opt): array
    {
        $artifacts = []; $manifest = ['files' => []];

        $mode = $opt['mode'] ?? 'full'; // full|schema
        $gzip = (bool)($opt['gzip'] ?? false);

        $path = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$c->database}_my_{$mode}_{$c->timestamp}" . ($c->noteTag ?? '') . ".sql";

        $args = [
            $c->tools['mysqldump'],
            '--host=' . $c->host,
            '--port=' . $c->port,
            '--user=' . $c->username,
            '--password=' . ($c->password ?? ''),
            '--single-transaction','--routines','--events','--triggers','--hex-blob','--set-gtid-purged=OFF'
        ];
        if ($mode === 'schema') $args[] = '--no-data';
        $args[] = $c->database;

        $this->runner->runToFile($args, $path);
        if ($gzip) $path = $this->runner->gzip($path);

        $artifacts[] = $path; $manifest['files'][] = basename($path);
        return ['artifacts' => $artifacts, 'manifest' => $manifest];
    }
}

<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Dump;

use Tekkenking\Dbbackupman\Contracts\Dumper;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Illuminate\Support\Facades\File;

class MySqlDumper implements Dumper
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function dump(ConnectionInfo $c, array $opt): array
    {
        $artifacts = [];
        $manifest  = ['files' => []];

        $mode    = $opt['mode'] ?? 'full'; // full|schema
        $gzip    = (bool)($opt['gzip'] ?? false);
        $keepRaw = (bool) config('dbbackup.keep_raw', false);

        $base    = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $rawPath = $base . "{$c->database}_my_{$mode}_{$c->timestamp}" . ($c->noteTag ?? '') . ".sql";

        $bin = $c->tools['mysqldump'] ?? 'mysqldump';

        $args = [
            $bin,
            '--host=' . $c->host,
            '--port=' . $c->port,
            '--user=' . $c->username,
            '--single-transaction',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--set-gtid-purged=OFF',
        ];
        if (!empty($c->password)) {
            // Note: visible in process list; run on trusted hosts
            $args[] = '--password=' . $c->password;
        }
        if ($mode === 'schema') {
            $args[] = '--no-data';
        }
        $args[] = $c->database;

        // Dump to raw .sql
        $this->runner->runToFile($args, $rawPath);

        // Optionally gzip and clean up raw
        $finalPath = $rawPath;
        if ($gzip) {
            $gzPath = $this->runner->gzip($rawPath);
            if (!$keepRaw) {
                @File::delete($rawPath);
            }
            $finalPath = $gzPath;
        }

        $artifacts[] = $finalPath;
        $manifest['files'][] = basename($finalPath);

        return ['artifacts' => $artifacts, 'manifest' => $manifest];
    }
}

<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Dump;

use Tekkenking\Dbbackupman\Contracts\Dumper;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Illuminate\Support\Facades\File;

class PostgresDumper implements Dumper
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function dump(ConnectionInfo $c, array $opt): array
    {
        $artifacts = [];
        $manifest  = ['files' => []];

        $noOwner    = (bool)($opt['no_owner'] ?? false);
        $mode       = $opt['mode'] ?? 'full';           // full|schema
        $perSchema  = (bool)($opt['per_schema'] ?? false);
        $include    = (array)($opt['include'] ?? []);
        $exclude    = (array)($opt['exclude'] ?? []);
        $globals    = (bool)($opt['globals'] ?? false);
        $gzip       = (bool)($opt['gzip'] ?? false);

        $dsn = sprintf(
            "host=%s port=%d dbname=%s user=%s",
            $this->libpq($c->host), $c->port, $this->libpq($c->database), $this->libpq($c->username)
        );
        $env = $_ENV;
        if ($c->password) $env['PGPASSWORD'] = $c->password;

        // main dump
        $dumpPath = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$c->database}_pg_{$mode}_{$c->timestamp}" . ($c->noteTag ?? '') . ".dump";

        $cmd = [$c->tools['pg_dump'], '--format=custom', '--file', $dumpPath, '--dbname=' . $dsn];
        if ($mode === 'schema') $cmd[] = '--schema-only';
        if ($noOwner)           $cmd[] = '--no-owner';

        $this->runner->run($cmd, $env);
        if ($gzip) $dumpPath = $this->runner->gzip($dumpPath);
        $artifacts[] = $dumpPath; $manifest['files'][] = basename($dumpPath);

        // globals
        if ($globals) {
            $gPath = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                "globals_pg_{$c->timestamp}" . ($c->noteTag ?? '') . ".sql";

            $this->runner->runToFile([$c->tools['pg_dumpall'], '-g', '--dbname=' . $dsn], $gPath, $env);
            if ($gzip) $gPath = $this->runner->gzip($gPath);
            $artifacts[] = $gPath; $manifest['files'][] = basename($gPath);
        }

        // per schema (optional, tolerant)
        if ($perSchema) {
            $pdo = new \PDO(
                "pgsql:host={$c->host};port={$c->port};dbname={$c->database}",
                $c->username, $c->password ?? ''
            );
            $schemas = $pdo->query(
                "SELECT schema_name FROM information_schema.schemata
                 WHERE schema_name NOT IN ('pg_catalog','information_schema')
                 ORDER BY schema_name"
            )->fetchAll(\PDO::FETCH_COLUMN);

            if ($include) $schemas = array_values(array_intersect($schemas, $include));
            if ($exclude) $schemas = array_values(array_diff($schemas, $exclude));

            foreach ($schemas as $schema) {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', $schema);
                $p = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                    "{$c->database}_pg_schema-{$safe}_{$c->timestamp}" . ($c->noteTag ?? '') . ".dump";
                $cmd = [$c->tools['pg_dump'],'--format=custom','--file',$p,'-n',$schema,'--dbname='.$dsn];
                if ($noOwner) $cmd[] = '--no-owner';

                try {
                    $this->runner->run($cmd, $env);
                    if ($gzip) $p = $this->runner->gzip($p);
                    $artifacts[] = $p; $manifest['files'][] = basename($p);
                } catch (\Throwable) {
                    // continue other schemas
                }
            }
        }

        return ['artifacts' => $artifacts, 'manifest' => $manifest];
    }

    private function libpq(string $v): string
    {
        return preg_match('/\s|[\'"]/', $v) ? "'".str_replace("'", "\\'", $v)."'" : $v;
    }
}

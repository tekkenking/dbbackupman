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
        $keepRaw    = (bool) config('dbbackup.keep_raw', false);

        $binDump    = $c->tools['pg_dump']    ?? 'pg_dump';
        $binDumpAll = $c->tools['pg_dumpall'] ?? 'pg_dumpall';

        $dsn = sprintf(
            "host=%s port=%d dbname=%s user=%s",
            $this->libpq($c->host), $c->port, $this->libpq($c->database), $this->libpq($c->username)
        );

        // pass password via env (avoids exposing in ps)
        $env = $_ENV;
        if ($c->password) {
            $env['PGPASSWORD'] = $c->password;
        }

        $base = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // ---- main dump ----
        $rawDumpPath = $base . "{$c->database}_pg_{$mode}_{$c->timestamp}" . ($c->noteTag ?? '') . ".dump";
        $cmd = [$binDump, '--format=custom', '--file', $rawDumpPath, '--dbname=' . $dsn];
        if ($mode === 'schema') $cmd[] = '--schema-only';
        if ($noOwner)           $cmd[] = '--no-owner';

        $this->runner->run($cmd, $env);

        $dumpPath = $rawDumpPath;
        if ($gzip) {
            $gz = $this->runner->gzip($rawDumpPath);
            if (!$keepRaw) {
                @File::delete($rawDumpPath);
            }
            $dumpPath = $gz;
        }
        $artifacts[] = $dumpPath;
        $manifest['files'][] = basename($dumpPath);

        // ---- globals (roles/tablespaces) ----
        if ($globals) {
            $rawGPath = $base . "globals_pg_{$c->timestamp}" . ($c->noteTag ?? '') . ".sql";
            $this->runner->runToFile([$binDumpAll, '-g', '--dbname=' . $dsn], $rawGPath, $env);

            $gPath = $rawGPath;
            if ($gzip) {
                $gz = $this->runner->gzip($rawGPath);
                if (!$keepRaw) {
                    @File::delete($rawGPath);
                }
                $gPath = $gz;
            }
            $artifacts[] = $gPath;
            $manifest['files'][] = basename($gPath);
        }

        // ---- per schema (optional) ----
        if ($perSchema) {
            $pdo = new \PDO(
                "pgsql:host={$c->host};port={$c->port};dbname={$c->database}",
                $c->username,
                $c->password ?? ''
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $schemas = $pdo->query(
                "SELECT schema_name FROM information_schema.schemata
                 WHERE schema_name NOT IN ('pg_catalog','information_schema')
                 ORDER BY schema_name"
            )->fetchAll(\PDO::FETCH_COLUMN);

            if ($include) $schemas = array_values(array_intersect($schemas, $include));
            if ($exclude) $schemas = array_values(array_diff($schemas, $exclude));

            foreach ($schemas as $schema) {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $schema);
                $rawP = $base . "{$c->database}_pg_schema-{$safe}_{$c->timestamp}" . ($c->noteTag ?? '') . ".dump";
                $sCmd = [$binDump, '--format=custom', '--file', $rawP, '-n', $schema, '--dbname=' . $dsn];
                if ($noOwner) $sCmd[] = '--no-owner';

                try {
                    $this->runner->run($sCmd, $env);

                    $p = $rawP;
                    if ($gzip) {
                        $gz = $this->runner->gzip($rawP);
                        if (!$keepRaw) {
                            @File::delete($rawP);
                        }
                        $p = $gz;
                    }

                    $artifacts[] = $p;
                    $manifest['files'][] = basename($p);
                } catch (\Throwable) {
                    // tolerate per-schema failures and continue
                }
            }
        }

        return ['artifacts' => $artifacts, 'manifest' => $manifest];
    }

    private function libpq(string $v): string
    {
        return preg_match('/\s|[\'"]/', $v)
            ? "'" . str_replace("'", "\\'", $v) . "'"
            : $v;
    }
}

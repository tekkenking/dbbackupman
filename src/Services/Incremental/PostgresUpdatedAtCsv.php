<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Incremental;

use Tekkenking\Dbbackupman\Contracts\IncrementalStrategy;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Illuminate\Support\Facades\File;

class PostgresUpdatedAtCsv implements IncrementalStrategy
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function run(ConnectionInfo $c, array $opt): array
    {
        $sinceIso = (string)($opt['since_iso'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-1 day')->format(DATE_ATOM));
        $gzip = (bool)($opt['gzip'] ?? false);
        $include = (array)($opt['include'] ?? []); // schema.table patterns (* allowed)
        $exclude = (array)($opt['exclude'] ?? []);

        $dsn = sprintf("host=%s port=%d dbname=%s user=%s",
            $this->libpq($c->host), $c->port, $this->libpq($c->database), $this->libpq($c->username));
        $env = $_ENV; if ($c->password) $env['PGPASSWORD'] = $c->password;

        $pdo = new \PDO(
            "pgsql:host={$c->host};port={$c->port};dbname={$c->database}",
            $c->username, $c->password ?? ''
        );

        $rows = $pdo->query(<<<SQL
SELECT n.nspname AS s, c.relname AS t
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind='r'
  AND n.nspname NOT IN ('pg_catalog','information_schema')
  AND EXISTS (
      SELECT 1 FROM pg_attribute a JOIN pg_type ty ON ty.oid=a.atttypid
      WHERE a.attrelid=c.oid AND a.attname='updated_at'
  )
ORDER BY n.nspname, c.relname
SQL)->fetchAll(\PDO::FETCH_ASSOC);

        $tables = array_map(fn($r) => [$r['s'], $r['t']], $rows);
        $tables = $this->filterTables($tables, $include, $exclude);

        $dir = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$c->database}_pg_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . "_csv";
        if (!File::isDirectory($dir)) File::makeDirectory($dir, 0775, true);

        $artifacts = [];
        foreach ($tables as [$schema, $table]) {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', "{$schema}_{$table}");
            $csv  = $dir . DIRECTORY_SEPARATOR . "{$safe}.csv";
            $sql  = "COPY (SELECT * FROM \"{$schema}\".\"{$table}\" WHERE \"updated_at\" > TIMESTAMP '" . addslashes($sinceIso) . "') TO STDOUT WITH CSV HEADER";
            $this->runner->runToFile([$c->tools['psql'],'--no-align','--tuples-only','--dbname='.$dsn,'-c',$sql], $csv, $env, null);
            if (File::exists($csv) && File::size($csv) > 0) {
                if ($gzip) $csv = $this->runner->gzip($csv);
                $artifacts[] = $csv;
            } else {
                if (File::exists($csv)) File::delete($csv);
            }
        }

        return [
            'artifacts' => $artifacts,
            'manifest_meta' => [
                'incremental_type' => 'updated_at',
                'since_utc'        => $sinceIso,
                'next_since_utc'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
        ];
    }

    private function filterTables(array $tables, array $inc, array $exc): array
    {
        $match = function (array $pats, string $s, string $t): bool {
            foreach ($pats as $pat) {
                [$ps,$pt] = str_contains($pat,'.') ? explode('.', $pat, 2) : ['*',$pat];
                $ps = '/^'.str_replace('\\*','.*',preg_quote($ps,'/')).'$/i';
                $pt = '/^'.str_replace('\\*','.*',preg_quote($pt,'/')).'$/i';
                if (preg_match($ps,$s) && preg_match($pt,$t)) return true;
            }
            return false;
        };

        if ($inc) $tables = array_values(array_filter($tables, fn($st) => $match($inc, $st[0], $st[1])));
        if ($exc) $tables = array_values(array_filter($tables, fn($st) => !$match($exc, $st[0], $st[1])));
        return $tables;
    }

    private function libpq(string $v): string
    {
        return preg_match('/\s|[\'"]/', $v) ? "'".str_replace("'", "\\'", $v)."'" : $v;
    }
}

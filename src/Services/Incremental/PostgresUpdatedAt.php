<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Incremental;

use Tekkenking\Dbbackupman\Contracts\IncrementalStrategy;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Illuminate\Support\Facades\File;

class PostgresUpdatedAt implements IncrementalStrategy
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function run(ConnectionInfo $c, array $opt): array
    {
        // Parse date range parameters
        $fromDate = $this->parseDate($opt['from_date'] ?? null);
        $toDate = $this->parseDate($opt['to_date'] ?? null);
        
        // Fallback to since_iso for backward compatibility
        if (!$fromDate) {
            $sinceIso = (string)($opt['since_iso'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('-1 day')->format(DATE_ATOM));
            $fromDate = new \DateTimeImmutable($sinceIso, new \DateTimeZone('UTC'));
        }
        
        // Default to_date to now if not provided
        if (!$toDate) {
            $toDate = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        
        $format = strtolower((string)($opt['format'] ?? 'csv')); // csv|sql
        $outputMode = strtolower((string)($opt['output_mode'] ?? 'separate')); // separate|combined
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

        if ($format === 'csv') {
            return $this->exportCsv($c, $tables, $fromDate, $toDate, $gzip, $dsn, $env);
        } else {
            return $this->exportSql($c, $tables, $fromDate, $toDate, $gzip, $outputMode, $dsn, $env);
        }
    }

    /**
     * Export tables as CSV files (one per table)
     */
    private function exportCsv(ConnectionInfo $c, array $tables, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, bool $gzip, string $dsn, array $env): array
    {
        $dir = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$c->database}_pg_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . "_csv";
        if (!File::isDirectory($dir)) File::makeDirectory($dir, 0775, true);

        $artifacts = [];
        foreach ($tables as [$schema, $table]) {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', "{$schema}_{$table}");
            $csv  = $dir . DIRECTORY_SEPARATOR . "{$safe}.csv";
            $sql  = sprintf(
                "COPY (SELECT * FROM \"%s\".\"%s\" WHERE \"updated_at\" >= TIMESTAMP '%s' AND \"updated_at\" <= TIMESTAMP '%s') TO STDOUT WITH CSV HEADER",
                addslashes($schema),
                addslashes($table),
                addslashes($fromDate->format('Y-m-d H:i:s')),
                addslashes($toDate->format('Y-m-d H:i:s'))
            );
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
                'format'           => 'csv',
                'output_mode'      => 'separate',
                'from_date'        => $fromDate->format('Y-m-d H:i:s'),
                'to_date'          => $toDate->format('Y-m-d H:i:s'),
                'since_utc'        => $fromDate->format(DATE_ATOM),
                'next_since_utc'   => $toDate->format(DATE_ATOM),
                'tables_exported'  => array_map(fn($t) => "{$t[0]}.{$t[1]}", $tables),
            ],
        ];
    }

    /**
     * Export tables as SQL files (separate or combined)
     */
    private function exportSql(ConnectionInfo $c, array $tables, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, bool $gzip, string $outputMode, string $dsn, array $env): array
    {
        $artifacts = [];
        $exportedTables = [];

        if ($outputMode === 'combined') {
            // Single file with all tables
            $sqlFile = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                "{$c->database}_pg_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . ".sql";
            
            $fh = fopen($sqlFile, 'w');
            if (!$fh) throw new \RuntimeException("Cannot open $sqlFile for writing");
            
            fwrite($fh, "BEGIN;\n\n");
            
            foreach ($tables as [$schema, $table]) {
                $this->writeSqlForTable($fh, $c, $schema, $table, $fromDate, $toDate, $dsn, $env);
                $exportedTables[] = "{$schema}.{$table}";
            }
            
            fwrite($fh, "COMMIT;\n");
            fclose($fh);
            
            if (File::exists($sqlFile) && File::size($sqlFile) > 0) {
                if ($gzip) $sqlFile = $this->runner->gzip($sqlFile);
                $artifacts[] = $sqlFile;
            }
        } else {
            // Separate files per table
            $dir = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                "{$c->database}_pg_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . "_sql";
            if (!File::isDirectory($dir)) File::makeDirectory($dir, 0775, true);

            foreach ($tables as [$schema, $table]) {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', "{$schema}_{$table}");
                $sqlFile = $dir . DIRECTORY_SEPARATOR . "{$safe}.sql";
                
                $fh = fopen($sqlFile, 'w');
                if (!$fh) throw new \RuntimeException("Cannot open $sqlFile for writing");
                
                fwrite($fh, "BEGIN;\n\n");
                $this->writeSqlForTable($fh, $c, $schema, $table, $fromDate, $toDate, $dsn, $env);
                fwrite($fh, "COMMIT;\n");
                fclose($fh);
                
                if (File::exists($sqlFile) && File::size($sqlFile) > 0) {
                    if ($gzip) $sqlFile = $this->runner->gzip($sqlFile);
                    $artifacts[] = $sqlFile;
                    $exportedTables[] = "{$schema}.{$table}";
                } else {
                    if (File::exists($sqlFile)) File::delete($sqlFile);
                }
            }
        }

        return [
            'artifacts' => $artifacts,
            'manifest_meta' => [
                'incremental_type' => 'updated_at',
                'format'           => 'sql',
                'output_mode'      => $outputMode,
                'from_date'        => $fromDate->format('Y-m-d H:i:s'),
                'to_date'          => $toDate->format('Y-m-d H:i:s'),
                'since_utc'        => $fromDate->format(DATE_ATOM),
                'next_since_utc'   => $toDate->format(DATE_ATOM),
                'tables_exported'  => $exportedTables,
            ],
        ];
    }

    /**
     * Write SQL INSERT statements for a table with date filtering
     */
    private function writeSqlForTable($fh, ConnectionInfo $c, string $schema, string $table, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, string $dsn, array $env): void
    {
        // Get columns for the table
        $pdo = new \PDO(
            "pgsql:host={$c->host};port={$c->port};dbname={$c->database}",
            $c->username, $c->password ?? ''
        );
        
        $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position");
        $stmt->execute([$schema, $table]);
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        if (empty($columns)) return;
        
        $columnList = implode(', ', array_map(fn($c) => "\"{$c}\"", $columns));
        
        // Export data using COPY to get CSV, then convert to INSERT statements
        $tmpCsv = tempnam(sys_get_temp_dir(), 'pgsql_export_');
        $sql = sprintf(
            "COPY (SELECT * FROM \"%s\".\"%s\" WHERE \"updated_at\" >= TIMESTAMP '%s' AND \"updated_at\" <= TIMESTAMP '%s') TO STDOUT WITH CSV",
            addslashes($schema),
            addslashes($table),
            addslashes($fromDate->format('Y-m-d H:i:s')),
            addslashes($toDate->format('Y-m-d H:i:s'))
        );
        
        $this->runner->runToFile([$c->tools['psql'],'--no-align','--tuples-only','--dbname='.$dsn,'-c',$sql], $tmpCsv, $env, null);
        
        if (File::exists($tmpCsv) && File::size($tmpCsv) > 0) {
            fwrite($fh, "-- Table: {$schema}.{$table}\n");
            
            // Read CSV and convert to INSERT statements
            if (($csvFh = fopen($tmpCsv, 'r')) !== false) {
                while (($line = fgets($csvFh)) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;
                    
                    // Parse CSV line and escape for SQL
                    $values = str_getcsv($line);
                    $escapedValues = array_map(function($val) use ($pdo) {
                        if ($val === '' || $val === null) return 'NULL';
                        return $pdo->quote($val);
                    }, $values);
                    
                    fwrite($fh, "INSERT INTO \"{$schema}\".\"{$table}\" ({$columnList}) VALUES (" . implode(', ', $escapedValues) . ");\n");
                }
                fclose($csvFh);
            }
            
            fwrite($fh, "\n");
        }
        
        if (File::exists($tmpCsv)) File::delete($tmpCsv);
    }

    /**
     * Parse date from string to DateTimeImmutable
     */
    private function parseDate(?string $date): ?\DateTimeImmutable
    {
        if (!$date) return null;
        
        try {
            // Try parsing with time first
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $date)) {
                return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            }
            // Try date only format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
            }
            // Try ISO8601 format
            return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid date format: {$date}. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS");
        }
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

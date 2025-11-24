<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Incremental;

use Tekkenking\Dbbackupman\Contracts\IncrementalStrategy;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Illuminate\Support\Facades\File;

class MySqlUpdatedAt implements IncrementalStrategy
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
                ->modify('-1 day')->format('Y-m-d H:i:s'));
            $fromDate = new \DateTimeImmutable($sinceIso, new \DateTimeZone('UTC'));
        }
        
        // Default to_date to now if not provided
        if (!$toDate) {
            $toDate = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        
        $format = strtolower((string)($opt['format'] ?? 'csv')); // csv|sql
        $outputMode = strtolower((string)($opt['output_mode'] ?? 'separate')); // separate|combined
        $gzip = (bool)($opt['gzip'] ?? false);
        $include = (array)($opt['include'] ?? []); // table names
        $exclude = (array)($opt['exclude'] ?? []);

        // Discover tables with updated_at column
        $pdo = new \PDO(
            "mysql:host={$c->host};port={$c->port};dbname={$c->database}",
            $c->username, $c->password ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $rows = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'updated_at' " .
            "GROUP BY TABLE_NAME ORDER BY TABLE_NAME"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $tables = array_map(fn($r) => $r['TABLE_NAME'], $rows);
        $tables = $this->filterTables($tables, $include, $exclude);

        if ($format === 'csv') {
            return $this->exportCsv($c, $tables, $fromDate, $toDate, $gzip);
        } else {
            return $this->exportSql($c, $tables, $fromDate, $toDate, $gzip, $outputMode);
        }
    }

    /**
     * Export tables as CSV files (one per table)
     */
    private function exportCsv(ConnectionInfo $c, array $tables, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, bool $gzip): array
    {
        $dir = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$c->database}_my_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . "_csv";
        if (!File::isDirectory($dir)) File::makeDirectory($dir, 0775, true);

        $artifacts = [];
        foreach ($tables as $table) {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', $table);
            $csv  = $dir . DIRECTORY_SEPARATOR . "{$safe}.csv";
            
            // Build WHERE clause with properly escaped dates
            $fromStr = $fromDate->format('Y-m-d H:i:s');
            $toStr = $toDate->format('Y-m-d H:i:s');
            
            // Use mysql client configured in tools
            $cmd = [
                $c->tools['mysql'] ?? 'mysql',
                '--host=' . $c->host,
                '--port=' . $c->port,
                '--user=' . $c->username,
                '--batch',
                '--skip-column-names',
                '--execute=SELECT * FROM `' . str_replace('`', '``', $table) . 
                    '` WHERE updated_at >= \'' . str_replace("'", "\\'", $fromStr) . 
                    '\' AND updated_at <= \'' . str_replace("'", "\\'", $toStr) . '\'',
                $c->database,
            ];
            
            if ($c->password) {
                $cmd[] = '--password=' . $c->password;
            }
            
            $this->runner->runToFile($cmd, $csv, [], null);
            
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
                'tables_exported'  => $tables,
            ],
        ];
    }

    /**
     * Export tables as SQL files (separate or combined)
     */
    private function exportSql(ConnectionInfo $c, array $tables, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, bool $gzip, string $outputMode): array
    {
        $artifacts = [];
        $exportedTables = [];

        // Build WHERE clause with properly escaped dates
        $fromStr = $fromDate->format('Y-m-d H:i:s');
        $toStr = $toDate->format('Y-m-d H:i:s');
        $whereClause = 'updated_at >= \'' . str_replace("'", "\\'", $fromStr) . 
                       '\' AND updated_at <= \'' . str_replace("'", "\\'", $toStr) . '\'';

        if ($outputMode === 'combined') {
            // Single file with all tables
            $sqlFile = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                "{$c->database}_my_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . ".sql";
            
            $fh = fopen($sqlFile, 'w');
            if (!$fh) throw new \RuntimeException("Cannot open $sqlFile for writing");
            
            foreach ($tables as $table) {
                $this->dumpTableToFile($fh, $c, $table, $whereClause);
                $exportedTables[] = $table;
            }
            
            fclose($fh);
            
            if (File::exists($sqlFile) && File::size($sqlFile) > 0) {
                if ($gzip) $sqlFile = $this->runner->gzip($sqlFile);
                $artifacts[] = $sqlFile;
            }
        } else {
            // Separate files per table
            $dir = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                "{$c->database}_my_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . "_sql";
            if (!File::isDirectory($dir)) File::makeDirectory($dir, 0775, true);

            foreach ($tables as $table) {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', $table);
                $sqlFile = $dir . DIRECTORY_SEPARATOR . "{$safe}.sql";
                
                $fh = fopen($sqlFile, 'w');
                if (!$fh) throw new \RuntimeException("Cannot open $sqlFile for writing");
                
                $this->dumpTableToFile($fh, $c, $table, $whereClause);
                fclose($fh);
                
                if (File::exists($sqlFile) && File::size($sqlFile) > 0) {
                    if ($gzip) $sqlFile = $this->runner->gzip($sqlFile);
                    $artifacts[] = $sqlFile;
                    $exportedTables[] = $table;
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
                'tables_exported'  => $exportedTables,
            ],
        ];
    }

    /**
     * Dump a single table to file handle using mysqldump
     */
    private function dumpTableToFile($fh, ConnectionInfo $c, string $table, string $whereClause): void
    {
        $cmd = [
            $c->tools['mysqldump'],
            '--host=' . $c->host,
            '--port=' . $c->port,
            '--user=' . $c->username,
            '--no-create-info',
            '--skip-triggers',
            '--complete-insert',
            '--where=' . $whereClause,
            $c->database,
            $table,
        ];
        
        if ($c->password) {
            $cmd[] = '--password=' . $c->password;
        }

        $p = new \Symfony\Component\Process\Process($cmd);
        $p->setTimeout(null)->run(function ($type, $buffer) use ($fh) {
            if ($type === \Symfony\Component\Process\Process::OUT) {
                fwrite($fh, $buffer);
            }
        });

        if (!$p->isSuccessful()) {
            throw new \RuntimeException("mysqldump failed for table $table: " . $p->getErrorOutput());
        }
    }

    /**
     * Filter tables based on include/exclude patterns
     */
    private function filterTables(array $tables, array $inc, array $exc): array
    {
        $match = function (array $pats, string $t): bool {
            foreach ($pats as $pat) {
                $regex = '/^' . str_replace('\\*', '.*', preg_quote($pat, '/')) . '$/i';
                if (preg_match($regex, $t)) return true;
            }
            return false;
        };

        if ($inc) $tables = array_values(array_filter($tables, fn($t) => $match($inc, $t)));
        if ($exc) $tables = array_values(array_filter($tables, fn($t) => !$match($exc, $t)));
        return $tables;
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
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Invalid date format: {$date}. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS");
        }
    }
}

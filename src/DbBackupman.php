<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman;

#namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class DbBackupman extends Command
{
    protected $signature = 'db:backup
        {--connection= : Laravel DB connection name (defaults to default connection)}
        {--driver= : Force driver (pgsql|mysql|mariadb); inferred from connection if omitted}

        {--out= : Local output dir (default: storage/app/db-backups)}

        {--disk= : Filesystem disk(s) to upload artifacts (CSV allowed: s3,wasabi)}
        {--disks= : Alternative to --disk; CSV list of disks}
        {--remote-path= : Fallback remote base path used by disks not present in --remote-map}
        {--remote-map= : Per-disk remote paths (JSON or CSV pairs: s3:backups/prod,wasabi:backups/dr)}

        {--retention-keep= : Keep the most recent N backup sets (applied local + remote)}
        {--retention-days= : Delete backup sets older than D days (applied local + remote)}

        {--mode=full : Backup mode: full|schema|incremental}
        {--incremental-type= : For incremental: binlog (mysql/mariadb) | updated_at (pgsql)}
        {--since= : ISO8601 timestamp for PG updated_at mode (fallback if no state)}

        {--state-disk= : Disk to store state (defaults to first upload disk, else local)}
        {--state-path= : Remote path for state JSON (defaults to {diskPath}/_state or "_state" if diskPath empty)}

        {--pg_dump=pg_dump : Path to pg_dump}
        {--psql=psql : Path to psql}
        {--pg_dumpall=pg_dumpall : Path to pg_dumpall}
        {--mysqldump=mysqldump : Path to mysqldump}
        {--mysql=mysql : Path to mysql}
        {--mysqlbinlog=mysqlbinlog : Path to mysqlbinlog}

        {--gzip : Gzip outputs}
        {--no-owner : Omit ownership (logical dumps)}

        {--per-schema : (PG-only) dump each non-system schema (full/schema mode)}
        {--include= : CSV schemas to include (PG per-schema)}
        {--exclude= : CSV schemas to exclude (PG per-schema)}

        {--globals : (PG) include roles/tablespaces via pg_dumpall -g}

        {--pg-csv-include= : (PG incremental) CSV of table patterns to include (schema.table, supports * wildcards)}
        {--pg-csv-exclude= : (PG incremental) CSV of table patterns to exclude (schema.table, supports * wildcards)}

        {--note= : Free-text tag for filenames}
    ';

    protected $description = 'Cross-DB backups (PostgreSQL/MySQL/MariaDB) with uploads, retention, and incremental options.';

    public function handle(): int
    {
        $connName = $this->option('connection') ?: config('database.default');
        $cfg = config("database.connections.$connName");
        if (!$cfg) throw new RuntimeException("DB connection [$connName] not found.");

        $driverRaw = strtolower($this->option('driver') ?: ($cfg['driver'] ?? ''));
        if ($driverRaw === 'pgsql') $driver = 'pgsql';
        elseif (in_array($driverRaw, ['mysql','mariadb'], true)) $driver = 'mysql';
        else throw new RuntimeException("Unsupported driver [$driverRaw]. Use pgsql|mysql.");

        $mode  = strtolower($this->option('mode') ?: 'full'); // full|schema|incremental
        $out   = $this->option('out') ?: storage_path('app/db-backups');

        // Upload disks (multi)
        $disksOpt    = $this->option('disks') ?: $this->option('disk') ?: '';
        $uploadDisks = $this->csv($disksOpt);

        // Remote paths: global fallback + per-disk map
        $remotePathPlain = trim((string)($this->option('remote-path') ?? ''), '/');   // may be ""
        $remoteMap       = $this->parseRemoteMap($this->option('remote-map'));       // e.g. ['s3'=>'backups/prod','wasabi'=>'backups/dr']

        // Retention
        $retKeep = $this->option('retention-keep') !== null ? (int)$this->option('retention-keep') : null;
        $retDays = $this->option('retention-days') !== null ? (int)$this->option('retention-days') : null;

        $gzip  = (bool) $this->option('gzip');
        $note  = $this->sanitizeTag($this->option('note') ?: null);

        // State storage
        $stateDisk = $this->option('state-disk') ?: ($uploadDisks[0] ?? null);
        // If user didn’t pass state-path, default to per-disk path + "/_state" when uploading, else just "_state" locally.
        $defaultStatePath = ($remotePathPlain !== '' ? $remotePathPlain.'/_state' : '_state');
        $statePath = trim((string)($this->option('state-path') ?? $defaultStatePath), '/');

        if (!File::isDirectory($out)) File::makeDirectory($out, 0775, true);
        $ts = CarbonImmutable::now('UTC')->format('Ymd_His');
        $dbName = $cfg['database'] ?? 'database';
        $envTag = $note ? "_$note" : '';

        $manifest = [
            'at_utc'     => CarbonImmutable::now('UTC')->toIso8601String(),
            'connection' => $connName,
            'driver'     => $driver,
            'database'   => $dbName,
            'mode'       => $mode,
            'gzip'       => $gzip,
            'note'       => $note,
            'files'      => [],
            'meta'       => [],
        ];
        //$artifacts = [];

        if ($driver === 'pgsql') {
            [$artifacts, $manifest] = $this->backupPostgres($cfg, $mode, $out, $ts, $envTag, $gzip, $manifest);
        } else {
            [$artifacts, $manifest] = $this->backupMySQL($cfg, $mode, $out, $ts, $envTag, $gzip, $manifest);
        }

        // Manifest
        $driverAbbr = $driver === 'pgsql' ? 'pg' : 'my';
        $manifestName = "{$dbName}_{$driverAbbr}_{$mode}_{$ts}{$envTag}.manifest.json";
        $manifestPath = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $manifestName;
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $artifacts[] = $manifestPath;

        // Uploads (per-disk remote base path)
        if (!empty($uploadDisks)) {
            foreach ($uploadDisks as $disk) {
                $diskPath = $this->remotePathForDisk($disk, $remoteMap, $remotePathPlain); // may be ""
                $this->uploadArtifacts($disk, $diskPath, $artifacts);
            }

            // Persist state on disk if incremental
            if ($mode === 'incremental') {
                // Choose state base path relative to chosen disk: if the state disk has a mapping, prefer that mapping
                $stateBase = $statePath;
                if ($stateDisk) {
                    $mapped = $this->remotePathForDisk($stateDisk, $remoteMap, $remotePathPlain);
                    // If user supplied state-path explicitly, respect it as-is; else use mapped+"/_state"
                    if ($this->option('state-path') === null) {
                        $stateBase = ($mapped !== '' ? $mapped.'/_state' : '_state');
                    }
                }
                $this->persistState($stateDisk, $stateBase, $connName, $driver, $manifest['meta']);
            }
        } elseif ($mode === 'incremental') {
            // Fallback: local state
            $this->persistLocalState($out, $connName, $driver, $manifest['meta']);
        }

        // Retention (local + per-disk)
        if ($retKeep !== null || $retDays !== null) {
            $this->applyRetentionLocal($out, $dbName, $driverAbbr, $mode, $retKeep, $retDays);
            foreach ($uploadDisks as $disk) {
                $diskPath = $this->remotePathForDisk($disk, $remoteMap, $remotePathPlain);
                $this->applyRetentionRemote($disk, $diskPath, $dbName, $driverAbbr, $mode, $retKeep, $retDays);
            }
        }

        $this->info('✅ Backup completed.');
        foreach ($artifacts as $f) $this->line('  • ' . $f);
        if (!empty($uploadDisks)) $this->line('↑ Uploaded to: '.implode(', ', $uploadDisks));

        return self::SUCCESS;
    }

    /* ============================== POSTGRES ============================== */

    private function backupPostgres(array $cfg, string $mode, string $out, string $ts, string $envTag, bool $gzip, array $manifest): array
    {
        $db = $cfg['database'];
        $fileBase = "{$db}_pg_{$mode}_{$ts}{$envTag}";
        $noOwner  = (bool)$this->option('no-owner');
        $pgDump   = $this->option('pg_dump') ?: 'pg_dump';
        $psql     = $this->option('psql') ?: 'psql';
        $pgDumpAll= $this->option('pg_dumpall') ?: 'pg_dumpall';
        $perSchema= (bool)$this->option('per-schema');
        $include  = $this->csv($this->option('include'));
        $exclude  = $this->csv($this->option('exclude'));
        $globals  = (bool)$this->option('globals');

        $dsn = $this->pgLibpqDsn($cfg);
        $env = $this->withPasswordEnv($cfg, 'PGPASSWORD');

        $artifacts = [];

        if ($mode === 'full' || $mode === 'schema') {
            $dumpPath = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$fileBase}.dump";
            $cmd = [$pgDump, '--format=custom', '--file', $dumpPath, '--dbname='.$dsn];
            if ($mode === 'schema') $cmd[] = '--schema-only';
            if ($noOwner) $cmd[] = '--no-owner';
            $this->runProcess($cmd, $env, '[PG] pg_dump failed');
            if ($gzip) $dumpPath = $this->gzip($dumpPath);
            $artifacts[] = $dumpPath;
            $manifest['files'][] = basename($dumpPath);

            if ($globals) {
                $gPath = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "globals_pg_{$ts}{$envTag}.sql";
                $proc  = new Process([$pgDumpAll, '-g', '--dbname='.$dsn], null, $env);
                $proc->setTimeout(null)->run();
                if (!$proc->isSuccessful()) $this->warn('[PG] pg_dumpall -g failed: '.$proc->getErrorOutput());
                else {
                    File::put($gPath, $proc->getOutput());
                    if ($gzip) $gPath = $this->gzip($gPath);
                    $artifacts[] = $gPath;
                    $manifest['files'][] = basename($gPath);
                }
            }

            if ($perSchema) {
                [$pdo] = $this->pgPdo($cfg);
                $schemas = $this->pgListNonSystemSchemas($pdo);
                if ($include) $schemas = array_values(array_intersect($schemas, $include));
                if ($exclude) $schemas = array_values(array_diff($schemas, $exclude));

                foreach ($schemas as $schema) {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/','-', $schema);
                    $f = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                        "{$db}_pg_schema-{$safe}_{$ts}{$envTag}.dump";
                    $cmd = [$pgDump, '--format=custom', '--file', $f, '-n', $schema, '--dbname='.$dsn];
                    if ($noOwner) $cmd[] = '--no-owner';
                    $this->runProcess($cmd, $env, "[PG] schema {$schema} dump failed", true);
                    if (File::exists($f)) {
                        if ($gzip) $f = $this->gzip($f);
                        $artifacts[] = $f;
                        $manifest['files'][] = basename($f);
                    }
                }
            }
        } elseif ($mode === 'incremental') {
            $type = strtolower($this->option('incremental-type') ?: 'updated_at');
            if ($type !== 'updated_at') {
                throw new RuntimeException("PostgreSQL incremental supports only --incremental-type=updated_at.");
            }

            $since = $this->loadSinceFromStateOrOption('pgsql') ?? CarbonImmutable::now('UTC')->subDay();
            $manifest['meta']['since_utc'] = $since->toIso8601String();
            $manifest['meta']['incremental_type'] = 'updated_at';

            [$pdo] = $this->pgPdo($cfg);
            $tables = $this->pgTablesWithUpdatedAt($pdo);

            $incPat = $this->csv($this->option('pg-csv-include'));
            $excPat = $this->csv($this->option('pg-csv-exclude'));
            if (!empty($incPat)) {
                $tables = array_values(array_filter($tables, fn($t) => $this->matchTablePat($t[0], $t[1], $incPat)));
            }
            if (!empty($excPat)) {
                $tables = array_values(array_filter($tables, fn($t) => !$this->matchTablePat($t[0], $t[1], $excPat)));
            }

            $root = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$fileBase}_csv";
            if (!File::isDirectory($root)) File::makeDirectory($root, 0775, true);

            foreach ($tables as [$schema, $table, $col]) {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', "{$schema}_{$table}");
                $csv = $root . DIRECTORY_SEPARATOR . "{$safe}.csv";
                $sql = "COPY (SELECT * FROM {$this->pgIdent($schema)}.{$this->pgIdent($table)} WHERE {$this->pgIdent($col)} > TIMESTAMP '".addslashes($since->toIso8601String())."') TO STDOUT WITH CSV HEADER";
                $cmd = [$psql, '--no-align', '--tuples-only', '--dbname='.$dsn, '-c', $sql];
                $this->runToFile($cmd, $env, $csv, "[PG] incremental copy failed for {$schema}.{$table}", true);
                if (File::exists($csv) && File::size($csv) > 0) {
                    if ($gzip) $csv = $this->gzip($csv);
                    $artifacts[] = $csv;
                    $manifest['files'][] = basename($csv);
                } else {
                    if (File::exists($csv)) File::delete($csv);
                }
            }

            $manifest['meta']['next_since_utc'] = CarbonImmutable::now('UTC')->toIso8601String();
        } else {
            throw new RuntimeException("Unknown mode [$mode]");
        }

        return [$artifacts, $manifest];
    }

    /* ============================== MYSQL / MARIADB ============================== */

    private function backupMySQL(array $cfg, string $mode, string $out, string $ts, string $envTag, bool $gzip, array $manifest): array
    {
        $db   = $cfg['database'];
        $fileBase = "{$db}_my_{$mode}_{$ts}{$envTag}";
        $mysqldump = $this->option('mysqldump') ?: 'mysqldump';
        $mysql     = $this->option('mysql') ?: 'mysql';
        $mysqlbinlog = $this->option('mysqlbinlog') ?: 'mysqlbinlog';

        $env = [];
        $artifacts = [];

        if ($mode === 'full' || $mode === 'schema') {
            $dumpPath = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$fileBase}.sql";
            $args = [
                $mysqldump,
                '--host='.$cfg['host'],
                '--port='.($cfg['port'] ?? 3306),
                '--user='.$cfg['username'],
                '--password='.$cfg['password'],
                '--single-transaction',
                '--routines',
                '--events',
                '--triggers',
                '--hex-blob',
                '--set-gtid-purged=OFF',
            ];
            if ($mode === 'schema') $args[] = '--no-data';
            $args[] = $db;

            $this->runToFile($args, $env, $dumpPath, '[MySQL] mysqldump failed');
            if ($gzip) $dumpPath = $this->gzip($dumpPath);
            $artifacts[] = $dumpPath;
            $manifest['files'][] = basename($dumpPath);
        } elseif ($mode === 'incremental') {
            $type = strtolower($this->option('incremental-type') ?: 'binlog');
            if ($type !== 'binlog') throw new RuntimeException("MySQL/MariaDB incremental supports only --incremental-type=binlog.");

            $state = $this->loadBinlogState('mysql');
            $pdo = $this->mysqlPdo($cfg);

            $status = $pdo->query('SHOW MASTER STATUS')->fetch(\PDO::FETCH_ASSOC) ?: null;
            $logs = $pdo->query('SHOW BINARY LOGS')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ((!$status || empty($status['File'])) && empty($logs)) {
                throw new RuntimeException('Binary logs not enabled or not accessible.');
            }
            $currentFile = $status['File'] ?? end($logs)['Log_name'];
            $currentPos  = (int)($status['Position'] ?? 4);

            $startFile = $state['file'] ?? $currentFile;
            $startPos  = isset($state['pos']) ? (int)$state['pos'] : 4;

            $logNames = array_values(array_filter(array_map(fn($r) => $r['Log_name'] ?? $r['File_name'] ?? null, $logs)));
            if (!in_array($startFile, $logNames, true)) $logNames[] = $startFile;
            if (!in_array($currentFile, $logNames, true)) $logNames[] = $currentFile;
            natsort($logNames); $logNames = array_values($logNames);

            $startIdx = array_search($startFile, $logNames, true);
            $endIdx   = array_search($currentFile, $logNames, true);
            if ($startIdx === false || $endIdx === false) $startIdx = $endIdx = array_search($currentFile, $logNames, true);

            $binPath = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$fileBase}.binlog.sql";
            $fh = fopen($binPath, 'w');
            if (!$fh) throw new RuntimeException("Cannot open $binPath for writing");

            for ($i = $startIdx; $i <= $endIdx; $i++) {
                $file = $logNames[$i];
                $args = [
                    $mysqlbinlog,
                    '--read-from-remote-server',
                    '--host='.$cfg['host'],
                    '--port='.($cfg['port'] ?? 3306),
                    '--user='.$cfg['username'],
                    '--password='.$cfg['password'],
                    '--verbose',
                ];
                if ($i === $startIdx) $args[] = '--start-position='.$startPos;
                $args[] = $file;

                $p = new Process($args, null, $env);
                $p->setTimeout(null);
                $p->run(function ($type, $buffer) use ($fh) {
                    if ($type === Process::OUT) fwrite($fh, $buffer);
                    else fwrite(STDERR, $buffer);
                });

                if (!$p->isSuccessful()) {
                    fclose($fh);
                    $this->warn("[MySQL] mysqlbinlog failed for $file\n".$p->getErrorOutput());
                    break;
                }
            }
            fclose($fh);

            if ($gzip && File::exists($binPath)) $binPath = $this->gzip($binPath);
            if (File::exists($binPath)) {
                $artifacts[] = $binPath;
                $manifest['files'][] = basename($binPath);
            }

            $manifest['meta']['incremental_type'] = 'binlog';
            $manifest['meta']['from'] = $state ?: ['file'=>$startFile,'pos'=>$startPos];
            $manifest['meta']['to']   = ['file'=>$currentFile,'pos'=>$currentPos];
        } else {
            throw new RuntimeException("Unknown mode [$mode]");
        }

        return [$artifacts, $manifest];
    }

    /* ============================== UPLOADS / STATE / RETENTION ============================== */

    private function uploadArtifacts(string $disk, string $remoteBase, array $localFiles): void
    {
        // $remoteBase is already trimmed of leading/trailing slashes; may be "" (root)
        $prefix = $remoteBase !== '' ? $remoteBase.'/' : '';
        $store = Storage::disk($disk);

        foreach ($localFiles as $path) {
            $name = basename($path);
            $remote = $prefix.$name;
            $fh = fopen($path, 'r');
            if (!$fh) { $this->warn("Failed to open $path for upload"); continue; }
            $ok = $store->put($remote, $fh);
            fclose($fh);
            $this->line($ok ? "↑ Uploaded: $disk://$remote" : "✗ Upload failed: $disk://$remote");
        }
    }

    private function persistState(?string $disk, string $statePath, string $conn, string $driver, array $meta): void
    {
        if (!$disk) return;
        $name = trim($statePath, '/');
        if ($driver === 'mysql' && ($meta['incremental_type'] ?? '') === 'binlog' && isset($meta['to'])) {
            $state = ['file' => $meta['to']['file'], 'pos' => $meta['to']['pos']];
            $this->saveStateJson($disk, $name, $conn, $driver, $state);
        }
        if ($driver === 'pgsql' && ($meta['incremental_type'] ?? '') === 'updated_at' && isset($meta['next_since_utc'])) {
            $state = ['since_utc' => $meta['next_since_utc']];
            $this->saveStateJson($disk, $name, $conn, $driver, $state);
        }
    }

    private function persistLocalState(string $out, string $conn, string $driver, array $meta): void
    {
        $localStateDir = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_state';
        if (!File::isDirectory($localStateDir)) File::makeDirectory($localStateDir, 0775, true);
        if ($driver === 'mysql' && ($meta['incremental_type'] ?? '') === 'binlog' && isset($meta['to'])) {
            $state = ['file' => $meta['to']['file'], 'pos' => $meta['to']['pos']];
            File::put("$localStateDir/{$conn}_mysql_state.json", json_encode($state, JSON_PRETTY_PRINT));
        }
        if ($driver === 'pgsql' && ($meta['incremental_type'] ?? '') === 'updated_at' && isset($meta['next_since_utc'])) {
            $state = ['since_utc' => $meta['next_since_utc']];
            File::put("$localStateDir/{$conn}_pgsql_state.json", json_encode($state, JSON_PRETTY_PRINT));
        }
    }

    private function saveStateJson(string $disk, string $statePath, string $conn, string $driver, array $state): void
    {
        $base = trim($statePath, '/');
        $name = ($base !== '' ? $base.'/' : '') . "{$conn}_{$driver}_state.json";
        Storage::disk($disk)->put($name, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $this->line("↳ Saved state to $disk://$name");
    }

    private function applyRetentionLocal(string $out, string $db, string $driverAbbr, string $mode, ?int $keep, ?int $days): void
    {
        $pattern = "{$db}_{$driverAbbr}_{$mode}_";
        $manifests = collect(File::files($out))
            ->filter(fn($f) => str_ends_with($f->getFilename(), '.manifest.json') && str_starts_with($f->getFilename(), $pattern))
            ->map(fn($f) => $f->getFilename())
            ->sort()
            ->values()
            ->all();

        $toDelete = $this->determineRetentionVictims($manifests, $keep, $days);
        if (empty($toDelete)) return;

        $this->line("🧹 Local retention: deleting ".count($toDelete)." backup set(s)");
        foreach ($toDelete as $token) {
            $glob = glob(rtrim($out, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."{$db}_{$driverAbbr}_{$mode}_{$token}*");
            foreach ($glob as $path) @File::delete($path);
            foreach (glob(rtrim($out, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."*{$token}*") as $path) {
                if (is_dir($path) && str_contains($path, "{$db}_{$driverAbbr}_{$mode}_")) @File::deleteDirectory($path);
            }
        }
    }

    private function applyRetentionRemote(string $disk, string $remoteBase, string $db, string $driverAbbr, string $mode, ?int $keep, ?int $days): void
    {
        $prefix = $remoteBase !== '' ? trim($remoteBase, '/').'/' : '';
        $store = Storage::disk($disk);
        $files = collect($store->files($remoteBase ?: ''))
            ->filter(fn($p) => str_ends_with($p, '.manifest.json') && str_contains($p, "{$db}_{$driverAbbr}_{$mode}_"))
            ->map(fn($p) => basename($p))
            ->sort()
            ->values()
            ->all();

        $toDelete = $this->determineRetentionVictims($files, $keep, $days);
        if (empty($toDelete)) return;

        $this->line("🧹 Remote retention on [$disk] at '".($remoteBase ?: '/')."': deleting ".count($toDelete)." backup set(s)");
        $all = $store->files($remoteBase ?: '');
        foreach ($toDelete as $token) {
            foreach ($all as $p) {
                if (str_contains($p, "_{$token}")) $store->delete($p);
            }
        }
    }

    /**
     * @param array $manifestFiles Sorted list of manifest filenames
     * @return array tokens (e.g., "20250929_153501" or "20250929_153501_note")
     */
    private function determineRetentionVictims(array $manifestFiles, ?int $keep, ?int $days): array
    {
        $items = [];
        foreach ($manifestFiles as $name) {
            if (preg_match('/_(\d{8}_\d{6}(?:_[A-Za-z0-9._-]+)?)\.manifest\.json$/', $name, $m)) {
                $token = $m[1];
                $ts = substr($token, 0, 15); // Ymd_His
                $items[] = ['name'=>$name, 'token'=>$token, 'ts'=>$ts];
            }
        }
        usort($items, fn($a,$b) => strcmp($a['ts'], $b['ts']));

        $victims = [];

        if ($days !== null) {
            $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Ymd_His');
            foreach ($items as $it) if ($it['ts'] < $cutoff) $victims[$it['token']] = true;
        }
        if ($keep !== null && $keep >= 0) {
            $excess = max(0, count($items) - $keep);
            for ($i = 0; $i < $excess; $i++) $victims[$items[$i]['token']] = true;
        }
        return array_keys($victims);
    }

    /* ============================== PROCESS / IO ============================== */

    private function runProcess(array $cmd, array $env, string $onError, bool $warnOnly = false): void
    {
        $p = new Process($cmd, null, $env);
        $p->setTimeout(null)->run();
        if (!$p->isSuccessful()) {
            if ($warnOnly) $this->warn($onError . "\n" . $p->getErrorOutput());
            else throw new RuntimeException($onError . "\n" . $p->getErrorOutput());
        }
    }

    private function runToFile(array $cmd, array $env, string $file, string $onError, bool $warnOnly = false): void
    {
        $p = new Process($cmd, null, $env);
        $p->setTimeout(null);

        $fh = fopen($file, 'w');
        if (!$fh) throw new RuntimeException("Cannot open file for writing: $file");

        $p->run(function ($type, $buffer) use ($fh) {
            if ($type === Process::OUT) fwrite($fh, $buffer);
            else fwrite(STDERR, $buffer);
        });
        fclose($fh);

        if (!$p->isSuccessful()) {
            if ($warnOnly) $this->warn($onError . "\n" . $p->getErrorOutput());
            else throw new RuntimeException($onError . "\n" . $p->getErrorOutput());
        }
    }

    private function gzip(string $path): string
    {
        $gz = $path . '.gz';
        $in = fopen($path, 'rb'); $out = gzopen($gz, 'wb9');
        while (!feof($in)) gzwrite($out, fread($in, 262144));
        gzclose($out); fclose($in);
        return $gz;
    }

    /* ============================== DSN / PDO HELPERS ============================== */

    private function withPasswordEnv(array $cfg, string $envName): array
    {
        $env = $_ENV;
        if (!empty($cfg['password'])) $env[$envName] = $cfg['password'];
        return $env;
    }

    private function csv(?string $csv): array
    {
        if (!$csv) return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($s) => $s !== ''));
    }

    private function sanitizeTag(?string $tag): ?string
    {
        return $tag ? preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($tag)) : null;
    }

    private function parseRemoteMap($raw): array
    {
        $map = [];
        if (empty($raw)) return $map;
        $s = trim((string)$raw);
        if ($s === '') return $map;

        // JSON object?
        if ($s[0] === '{') {
            $j = json_decode($s, true);
            if (is_array($j)) {
                foreach ($j as $k => $v) {
                    if (!is_string($k)) continue;
                    $map[$k] = trim((string)$v, '/'); // allow empty string ""
                }
                return $map;
            }
            $this->warn('remote-map JSON parse failed; falling back to CSV parser.');
        }

        // CSV pairs: disk:path,disk2:/x/y
        $pairs = array_values(array_filter(array_map('trim', explode(',', $s)), fn($p) => $p !== ''));
        foreach ($pairs as $pair) {
            $pos = strpos($pair, ':');
            if ($pos === false) continue;
            $disk = trim(substr($pair, 0, $pos));
            $path = trim(substr($pair, $pos + 1));
            if ($disk !== '') $map[$disk] = trim($path, '/'); // empty => root
        }
        return $map;
    }

    private function remotePathForDisk(string $disk, array $map, string $plain): string
    {
        // If disk specified in map: use its path (can be ""), else fallback to plain (can be ""), else ""
        if (array_key_exists($disk, $map)) return $map[$disk];
        return $plain; // may be empty string
    }

    private function pgLibpqDsn(array $cfg): string
    {
        $parts = [];
        $parts[] = 'host='. $this->libpqVal($cfg['host'] ?? '127.0.0.1');
        $parts[] = 'port='. ($cfg['port'] ?? 5432);
        $parts[] = 'dbname='. $this->libpqVal($cfg['database']);
        $parts[] = 'user='. $this->libpqVal($cfg['username']);
        return implode(' ', $parts);
    }

    private function pgPdo(array $cfg): array
    {
        $dsn = 'pgsql:host='.$cfg['host'].';port='.($cfg['port'] ?? 5432).';dbname='.$cfg['database'];
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'] ?? '', [PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        return [$pdo];
    }

    private function pgListNonSystemSchemas(PDO $pdo): array
    {
        $sql = "SELECT schema_name FROM information_schema.schemata
                WHERE schema_name NOT IN ('pg_catalog','information_schema')
                ORDER BY schema_name";
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($r)=>$r['schema_name'], $rows);
    }

    private function pgTablesWithUpdatedAt(PDO $pdo): array
    {
        $sql = <<<SQL
SELECT n.nspname AS schema_name, c.relname AS table_name, a.attname AS updated_col
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid
JOIN pg_type t ON t.oid = a.atttypid
WHERE c.relkind = 'r'
  AND n.nspname NOT IN ('pg_catalog','information_schema')
  AND a.attname = 'updated_at'
ORDER BY n.nspname, c.relname;
SQL;
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($r)=>[$r['schema_name'], $r['table_name'], $r['updated_col']], $rows);
    }

    private function matchTablePat(string $schema, string $table, array $patterns): bool
    {
        foreach ($patterns as $pat) {
            [$ps, $pt] = (str_contains($pat, '.') ? explode('.', $pat, 2) : ['*', $pat]);
            $ps = $this->wildcardToRegex($ps);
            $pt = $this->wildcardToRegex($pt);
            if (preg_match($ps, $schema) && preg_match($pt, $table)) return true;
        }
        return false;
    }

    private function wildcardToRegex(string $w): string
    {
        $w = str_replace(['\\','/'], ['\\\\','\\/'], $w);
        $w = preg_quote($w, '/');
        $w = str_replace('\\*', '.*', $w);
        return '/^'.$w.'$/i';
    }

    private function pgIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    private function libpqVal(string $v): string
    {
        if (preg_match('/\s|[\'"]/', $v)) return "'".str_replace("'", "\\'", $v)."'";
        return $v;
    }

    private function mysqlPdo(array $cfg): \PDO
    {
        $dsn = 'mysql:host='.$cfg['host'].';port='.($cfg['port'] ?? 3306).';dbname='.$cfg['database'];
        return new \PDO($dsn, $cfg['username'], $cfg['password'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}


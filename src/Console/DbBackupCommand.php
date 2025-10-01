<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Console;

use Tekkenking\Dbbackupman\Contracts\StateRepository;
use Tekkenking\Dbbackupman\Contracts\Uploader;
use Tekkenking\Dbbackupman\Services\Dump\MySqlDumper;
use Tekkenking\Dbbackupman\Services\Dump\PostgresDumper;
use Tekkenking\Dbbackupman\Services\Incremental\MySqlBinlog;
use Tekkenking\Dbbackupman\Services\Incremental\PostgresUpdatedAtCsv;
use Tekkenking\Dbbackupman\Services\Retention\RetentionService;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Tekkenking\Dbbackupman\Support\RemotePathResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DbBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--connection= : Laravel DB connection (defaults to database.default)}
        {--driver= : Force driver (pgsql|mysql|mariadb)}
        {--mode=full : full|schema|incremental}
        {--gzip : gzip outputs}

        {--per-schema : (PG) per schema dumps}
        {--include= : CSV schemas (PG per-schema)}
        {--exclude= : CSV schemas (PG per-schema)}
        {--globals : (PG) pg_dumpall -g}
        {--no-owner : (PG) omit ownership}
        {--since= : (PG incremental) ISO8601 since; else last state}

        {--pg-csv-include= : (PG incremental) CSV table patterns}
        {--pg-csv-exclude= : (PG incremental) CSV table patterns}

        {--out= : local output dir (default storage/app/db-backups)}
        {--disks= : CSV disks or use config(dbbackup.upload.disks)}
        {--remote-path= : fallback remote path or config(dbbackup.upload.remote_path)}
        {--remote-map= : JSON or CSV pairs disk:path; overrides per disk}
        {--state-disk= : disk to store state (default first disk)}
        {--state-path= : base path for state json; defaults to {diskPath}/_state}

        {--retention-keep= : keep last N sets}
        {--retention-days= : delete sets older than D days}
    ';

    protected $description = 'Database backup with uploads and retention (DbBackupman).';

    public function handle(
        ProcessRunner $runner,          // used for tool preflight
        Uploader $uploader,
        StateRepository $stateRepo,
        RetentionService $retention    // now used below
    ): int {
        $connName = $this->option('connection') ?: config('database.default');
        $cfg      = config("database.connections.$connName");
        if (!$cfg) {
            $this->components->error("Connection [$connName] not found.");
            return self::FAILURE;
        }

        $driverRaw = strtolower($this->option('driver') ?: ($cfg['driver'] ?? ''));
        $driver    = $driverRaw === 'pgsql' ? 'pgsql' : (in_array($driverRaw, ['mysql','mariadb']) ? 'mysql' : null);
        if (!$driver) {
            $this->components->error('Unsupported driver. Use pgsql|mysql.');
            return self::FAILURE;
        }

        $mode  = strtolower($this->option('mode') ?: 'full');
        $out   = $this->option('out') ?: storage_path('app/db-backups');

        $tools = config('dbbackup.tools');
        $disks = $this->csv($this->option('disks') ?: implode(',', config('dbbackup.upload.disks', [])));
        $remotePlain = (string)($this->option('remote-path') ?? config('dbbackup.upload.remote_path', ''));
        $remoteMap   = $this->parseRemoteMap($this->option('remote-map') ?? json_encode(config('dbbackup.upload.remote_map', [])));

        $retKeep = $this->numOrNull($this->option('retention-keep') ?? config('dbbackup.retention.keep'));
        $retDays = $this->numOrNull($this->option('retention-days') ?? config('dbbackup.retention.days'));
        $gzip    = (bool)$this->option('gzip');

        // Preflight: ensure external binaries exist
        try {
            $this->validateTools($runner, $tools, $driver, $mode, (bool)$this->option('globals'));
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }

        File::ensureDirectoryExists($out);

        $ts     = CarbonImmutable::now('UTC')->format('Ymd_His');
        $dbName = $cfg['database'];
        $note   = null;
        $noteTag= $note ? "_$note" : null;

        $conn = new ConnectionInfo(
            driver: $driver,
            host: $cfg['host'] ?? '127.0.0.1',
            port: (int)($cfg['port'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            database: $dbName,
            username: $cfg['username'],
            password: $cfg['password'] ?? null,
            tools: $tools,
            workdir: $out,
            timestamp: $ts,
            noteTag: $noteTag
        );

        $manifest = [
            'at_utc' => CarbonImmutable::now('UTC')->toIso8601String(),
            'connection' => $connName,
            'driver' => $driver,
            'database' => $dbName,
            'mode' => $mode,
            'gzip' => $gzip,
            'files' => [],
            'meta'  => [],
        ];
        $artifacts = [];

        if ($mode === 'incremental') {
            if ($driver === 'pgsql') {
                $since = $this->option('since') ?: null;
                $inc = app()->make(PostgresUpdatedAtCsv::class);
                $result = $inc->run($conn, [
                    'since_iso' => $since,
                    'include'   => $this->csv($this->option('pg-csv-include')),
                    'exclude'   => $this->csv($this->option('pg-csv-exclude')),
                    'gzip'      => $gzip,
                ]);
            } else {
                $stateDisk = $this->option('state-disk') ?: ($disks[0] ?? null);
                $stateBase = RemotePathResolver::forDisk($stateDisk ?? '', $remoteMap, $remotePlain);
                $statePath = trim((string)($this->option('state-path') ?? ($stateBase !== '' ? $stateBase.'/_state' : '_state')), '/');
                $stateName = ($statePath !== '' ? $statePath.'/' : '')."{$connName}_mysql_state.json";
                $state     = $stateDisk ? $stateRepo->load($stateDisk, $stateName)
                    : $stateRepo->load('local', storage_path("app/db-backups/_state/{$connName}_mysql_state.json"));

                $inc = app()->make(MySqlBinlog::class);
                $result = $inc->run($conn, [
                    'state' => $state,
                    'gzip'  => $gzip,
                ]);
            }
            $artifacts = array_merge($artifacts, $result['artifacts']);
            $manifest['files'] = array_map('basename', $result['artifacts']);
            $manifest['meta']  = $result['manifest_meta'];
        } else {
            $dumper = $driver === 'pgsql'
                ? app()->make(PostgresDumper::class)
                : app()->make(MySqlDumper::class);

            $result = $dumper->dump($conn, [
                'mode'       => $mode,
                'gzip'       => $gzip,
                'no_owner'   => (bool)$this->option('no-owner'),
                'per_schema' => (bool)$this->option('per-schema'),
                'include'    => $this->csv($this->option('include')),
                'exclude'    => $this->csv($this->option('exclude')),
                'globals'    => (bool)$this->option('globals'),
            ]);
            $artifacts = array_merge($artifacts, $result['artifacts']);
            $manifest  = array_merge_recursive($manifest, $result['manifest']);
        }

        // write manifest
        $abbr = $driver === 'pgsql' ? 'pg' : 'my';
        $manifestPath = rtrim($out, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$dbName}_{$abbr}_{$mode}_{$ts}" . ($noteTag ?? '') . ".manifest.json";
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $artifacts[] = $manifestPath;

        // uploads
        foreach ($disks as $disk) {
            $base = RemotePathResolver::forDisk($disk, $remoteMap, $remotePlain);
            $uploader->upload($disk, $base, $artifacts);
        }

        // state persist (incremental)
        if ($mode === 'incremental') {
            if ($driver === 'mysql') {
                $stateDisk = $this->option('state-disk') ?: ($disks[0] ?? null);
                $base = RemotePathResolver::forDisk($stateDisk ?? '', $remoteMap, $remotePlain);
                $statePath = trim((string)($this->option('state-path') ?? ($base !== '' ? $base.'/_state' : '_state')), '/');
                $name = ($statePath !== '' ? $statePath.'/' : '')."{$connName}_mysql_state.json";
                if ($stateDisk) $stateRepo->save($stateDisk, $name, $manifest['meta']['to'] ?? []);
                else $stateRepo->save('local', storage_path("app/db-backups/_state/{$connName}_mysql_state.json"), $manifest['meta']['to'] ?? []);
            } else {
                $stateDisk = $this->option('state-disk') ?: ($disks[0] ?? null);
                $base = RemotePathResolver::forDisk($stateDisk ?? '', $remoteMap, $remotePlain);
                $statePath = trim((string)($this->option('state-path') ?? ($base !== '' ? $base.'/_state' : '_state')), '/');
                $name = ($statePath !== '' ? $statePath.'/' : '')."{$connName}_pgsql_state.json";
                $state = ['since_utc' => $manifest['meta']['next_since_utc'] ?? null];
                if ($stateDisk) $stateRepo->save($stateDisk, $name, $state);
                else $stateRepo->save('local', storage_path("app/db-backups/_state/{$connName}_pgsql_state.json"), $state);
            }
        }

        // retention
        if ($retKeep !== null || $retDays !== null) {
            // local
            $pattern = "{$dbName}_{$abbr}_{$mode}_";
            $locals = collect(File::files($out))
                ->filter(fn($f) => str_ends_with($f->getFilename(), '.manifest.json') && str_starts_with($f->getFilename(), $pattern))
                ->map(fn($f) => $f->getFilename())->sort()->values()->all();
            $victims = $retention->victims($locals, $retKeep, $retDays); // ✅ use injected service
            foreach ($victims as $token) {
                foreach (glob(rtrim($out,'/')."/{$dbName}_{$abbr}_{$mode}_{$token}*") as $p) @File::delete($p);
            }

            // remote per disk
            foreach ($disks as $disk) {
                $base = RemotePathResolver::forDisk($disk, $remoteMap, $remotePlain);
                $files = collect(Storage::disk($disk)->files($base ?: ''))
                    ->filter(fn($p) => str_ends_with($p, '.manifest.json') && str_contains($p, "{$dbName}_{$abbr}_{$mode}_"))
                    ->map(fn($p) => basename($p))->sort()->values()->all();
                $victims = $retention->victims($files, $retKeep, $retDays); // ✅ use injected service
                $all = Storage::disk($disk)->files($base ?: '');
                foreach ($victims as $token) {
                    foreach ($all as $p) if (str_contains($p, "_{$token}")) Storage::disk($disk)->delete($p);
                }
            }
        }

        $this->components->info('Backup complete.');
        foreach ($artifacts as $f) $this->line("• $f");

        return self::SUCCESS;
    }

    /**
     * Ensure required external binaries exist and can run.
     * Throws RuntimeException with a friendly message if anything is missing.
     */
    private function validateTools(ProcessRunner $runner, array $tools, string $driver, string $mode, bool $globals): void
    {
        $toCheck = [];
        if ($driver === 'pgsql') {
            $toCheck[] = $tools['pg_dump'] ?? 'pg_dump';
            $toCheck[] = $tools['psql'] ?? 'psql';
            if ($globals) $toCheck[] = $tools['pg_dumpall'] ?? 'pg_dumpall';
        } else {
            $toCheck[] = $tools['mysqldump'] ?? 'mysqldump';
            if ($mode === 'incremental') $toCheck[] = $tools['mysqlbinlog'] ?? 'mysqlbinlog';
        }

        foreach ($toCheck as $bin) {
            try {
                $runner->run([$bin, '--version']);
            } catch (\Throwable $e) {
                throw new RuntimeException("Required tool not found or failed to run: {$bin}. {$e->getMessage()}");
            }
        }
    }

    private function csv(?string $s): array
    {
        if (!$s) return [];
        return array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== ''));
    }

    private function parseRemoteMap(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        if ($raw[0] === '{') {
            $j = json_decode($raw, true);
            return is_array($j) ? array_map(fn($v) => trim((string)$v, '/'), $j) : [];
        }
        $map = [];
        foreach ($this->csv($raw) as $pair) {
            $pos = strpos($pair, ':');
            if ($pos === false) continue;
            $disk = trim(substr($pair, 0, $pos));
            $path = trim(substr($pair, $pos+1));
            $map[$disk] = trim($path, '/');
        }
        return $map;
    }

    private function numOrNull($v): ?int
    {
        return $v === null || $v === '' ? null : (int)$v;
    }
}

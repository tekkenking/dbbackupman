<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman;

use Illuminate\Support\ServiceProvider;
use Tekkenking\Dbbackupman\Application\BackupContext;
use Tekkenking\Dbbackupman\Application\BackupOrchestrator;
use Tekkenking\Dbbackupman\Console\DbBackupCommand;
use Tekkenking\Dbbackupman\Contracts\StateRepository;
use Tekkenking\Dbbackupman\Contracts\Uploader;
use Tekkenking\Dbbackupman\Services\Dump\MySqlDumper;
use Tekkenking\Dbbackupman\Services\Dump\PostgresDumper;
use Tekkenking\Dbbackupman\Services\Incremental\MySqlBinlog;
use Tekkenking\Dbbackupman\Services\Incremental\MySqlUpdatedAt;
use Tekkenking\Dbbackupman\Services\Incremental\PostgresUpdatedAt;
use Tekkenking\Dbbackupman\Services\State\DiskStateRepository;
use Tekkenking\Dbbackupman\Services\Uploader\StorageUploader;
use Tekkenking\Dbbackupman\Support\DbBackupmanManager;
use Tekkenking\Dbbackupman\Support\OptionParser;
use Tekkenking\Dbbackupman\Support\OptionValidator;

class DbBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/dbbackup.php', 'dbbackup');

        $this->app->bind(Uploader::class, StorageUploader::class);
        $this->app->bind(StateRepository::class, DiskStateRepository::class);

        // Support utilities
        $this->app->bind(OptionParser::class, OptionParser::class);
        $this->app->bind(OptionValidator::class, OptionValidator::class);

        $this->app->singleton(BackupOrchestrator::class, function ($app): BackupOrchestrator {
            return new BackupOrchestrator([
                'pgsql' => function (BackupContext $ctx) use ($app): array {
                    $conn = $ctx->options['conn'];
                    $opt  = $ctx->options;

                    if ($ctx->mode === 'incremental') {
                        $result = $app->make(PostgresUpdatedAt::class)->run($conn, [
                            'since_iso'   => $opt['since_iso'] ?? null,
                            'from_date'   => $opt['from_date'] ?? null,
                            'to_date'     => $opt['to_date'] ?? null,
                            'format'      => $opt['incremental_format'] ?? 'csv',
                            'output_mode' => $opt['incremental_output'] ?? 'separate',
                            'include'     => $opt['pg_include'] ?? [],
                            'exclude'     => $opt['pg_exclude'] ?? [],
                            'gzip'        => $ctx->gzip,
                        ]);
                        return [
                            'artifacts'      => $result['artifacts'],
                            'manifest_files' => array_map('basename', $result['artifacts']),
                            'meta'           => $result['manifest_meta'],
                        ];
                    }

                    $result = $app->make(PostgresDumper::class)->dump($conn, [
                        'mode'       => $ctx->mode,
                        'gzip'       => $ctx->gzip,
                        'no_owner'   => $opt['no_owner'] ?? false,
                        'per_schema' => $opt['per_schema'] ?? false,
                        'include'    => $opt['include'] ?? [],
                        'exclude'    => $opt['exclude'] ?? [],
                        'globals'    => $opt['globals'] ?? false,
                    ]);
                    return [
                        'artifacts'      => $result['artifacts'],
                        'manifest_files' => $result['manifest']['files'] ?? [],
                        'meta'           => [],
                    ];
                },

                'mysql' => function (BackupContext $ctx) use ($app): array {
                    $conn = $ctx->options['conn'];
                    $opt  = $ctx->options;

                    if ($ctx->mode === 'incremental') {
                        if (($opt['incremental_type'] ?? 'binlog') === 'updated_at') {
                            $result = $app->make(MySqlUpdatedAt::class)->run($conn, [
                                'from_date'   => $opt['from_date'] ?? null,
                                'to_date'     => $opt['to_date'] ?? null,
                                'format'      => $opt['incremental_format'] ?? 'csv',
                                'output_mode' => $opt['incremental_output'] ?? 'separate',
                                'include'     => $opt['mysql_include'] ?? [],
                                'exclude'     => $opt['mysql_exclude'] ?? [],
                                'gzip'        => $ctx->gzip,
                            ]);
                        } else {
                            $result = $app->make(MySqlBinlog::class)->run($conn, [
                                'state' => $opt['state'] ?? [],
                                'gzip'  => $ctx->gzip,
                            ]);
                        }
                        return [
                            'artifacts'      => $result['artifacts'],
                            'manifest_files' => array_map('basename', $result['artifacts']),
                            'meta'           => $result['manifest_meta'],
                        ];
                    }

                    $result = $app->make(MySqlDumper::class)->dump($conn, [
                        'mode' => $ctx->mode,
                        'gzip' => $ctx->gzip,
                    ]);
                    return [
                        'artifacts'      => $result['artifacts'],
                        'manifest_files' => $result['manifest']['files'] ?? [],
                        'meta'           => [],
                    ];
                },
            ]);
        });

        // Facade accessor binding
        $this->app->singleton('dbbackupman.manager', fn () => new DbBackupmanManager());
        $this->app->alias('dbbackupman.manager', DbBackupmanManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/dbbackup.php' => config_path('dbbackup.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([DbBackupCommand::class]);
        }
    }
}

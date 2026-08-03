<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Application;

/**
 * Immutable value object that captures all parameters for a single backup run.
 */
final class BackupContext
{
    /**
     * @param string               $connection  Laravel DB connection name
     * @param string               $driver      Normalised driver: 'pgsql' | 'mysql'
     * @param string               $mode        Backup mode: 'full' | 'schema' | 'incremental'
     * @param bool                 $gzip        Whether to gzip output files
     * @param string               $outputDir   Local directory to write artifacts to
     * @param array<string,mixed>  $options     All remaining driver-specific options
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $driver,
        public readonly string $mode,
        public readonly bool $gzip,
        public readonly string $outputDir,
        public readonly array $options = [],
    ) {}
}

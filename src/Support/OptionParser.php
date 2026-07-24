<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

/**
 * Parses and normalises raw CLI option arrays into a consistent structure
 * that other services (OptionValidator, BackupOrchestrator, etc.) can consume.
 */
final class OptionParser
{
    /**
     * Parse a raw options array (typically from an Artisan command's option())
     * into a normalised map.
     *
     * @param  array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function parse(array $raw): array
    {
        $mode   = strtolower((string)($raw['mode'] ?? 'full'));
        $driver = strtolower((string)($raw['driver'] ?? ''));

        return [
            'connection'          => $raw['connection'] ?? null,
            'driver'              => $driver,
            'mode'                => $mode,
            'gzip'                => (bool)($raw['gzip'] ?? false),
            'out'                 => $raw['out'] ?? null,
            'disks'               => $this->csv($raw['disks'] ?? null),
            'remote_path'         => trim((string)($raw['remote-path'] ?? ''), '/'),
            'remote_map'          => $this->parseRemoteMap($raw['remote-map'] ?? ''),
            'retention_keep'      => $this->intOrNull($raw['retention-keep'] ?? null),
            'retention_days'      => $this->intOrNull($raw['retention-days'] ?? null),
            'per_schema'          => (bool)($raw['per-schema'] ?? false),
            'include'             => $this->csv($raw['include'] ?? null),
            'exclude'             => $this->csv($raw['exclude'] ?? null),
            'globals'             => (bool)($raw['globals'] ?? false),
            'no_owner'            => (bool)($raw['no-owner'] ?? false),
            'since'               => $raw['since'] ?? null,
            'incremental_type'    => strtolower((string)($raw['incremental-type'] ?? '')),
            'from_date'           => $raw['from-date'] ?? null,
            'to_date'             => $raw['to-date'] ?? null,
            'incremental_format'  => strtolower((string)($raw['incremental-format'] ?? 'csv')),
            'incremental_output'  => strtolower((string)($raw['incremental-output'] ?? 'separate')),
            'pg_csv_include'      => $this->csv($raw['pg-csv-include'] ?? null),
            'pg_csv_exclude'      => $this->csv($raw['pg-csv-exclude'] ?? null),
            'mysql_csv_include'   => $this->csv($raw['mysql-csv-include'] ?? null),
            'mysql_csv_exclude'   => $this->csv($raw['mysql-csv-exclude'] ?? null),
            'state_disk'          => $raw['state-disk'] ?? null,
            'state_path'          => $raw['state-path'] ?? null,
        ];
    }

    /**
     * Split a comma-separated string into a trimmed, filtered array.
     *
     * @return array<string>
     */
    public function csv(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $value)),
                fn (string $s) => $s !== ''
            )
        );
    }

    /**
     * Parse a remote-map value that may be JSON ("{"s3":"backups/prod"}")
     * or CSV pairs ("s3:backups/prod,wasabi:backups/dr").
     *
     * @return array<string,string>
     */
    public function parseRemoteMap(?string $raw): array
    {
        $raw = trim((string)$raw);

        if ($raw === '') {
            return [];
        }

        if ($raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            return array_map(fn ($v) => trim((string)$v, '/'), $decoded);
        }

        $map = [];
        foreach ($this->csv($raw) as $pair) {
            $pos = strpos($pair, ':');
            if ($pos === false) {
                continue;
            }
            $disk        = trim(substr($pair, 0, $pos));
            $path        = trim(substr($pair, $pos + 1), '/');
            $map[$disk]  = $path;
        }

        return $map;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }
}

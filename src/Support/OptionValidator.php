<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

/**
 * Validates a parsed options map produced by OptionParser and collects
 * any constraint violations as human-readable error messages.
 *
 * Usage:
 *   $validator = new OptionValidator();
 *   $validator->validate($parsed);
 *   if (!$validator->passes()) {
 *       foreach ($validator->errors() as $msg) { ... }
 *   }
 */
final class OptionValidator
{
    private const VALID_MODES = ['full', 'schema', 'incremental'];
    private const VALID_DRIVERS = ['pgsql', 'mysql', 'mariadb', ''];
    private const VALID_INCREMENTAL_FORMATS = ['csv', 'sql'];
    private const VALID_INCREMENTAL_OUTPUTS = ['separate', 'combined'];

    /** @var array<string> */
    private array $errors = [];

    /**
     * Validate the parsed options map.
     *
     * @param  array<string,mixed> $options  Output of OptionParser::parse()
     * @return $this
     */
    public function validate(array $options): static
    {
        $this->errors = [];

        $this->validateMode($options);
        $this->validateDriver($options);
        $this->validateIncrementalOptions($options);
        $this->validateDateRange($options);
        $this->validateRetention($options);

        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    // ── private validation helpers ─────────────────────────────────────────

    private function validateMode(array $options): void
    {
        $mode = $options['mode'] ?? '';
        if (!in_array($mode, self::VALID_MODES, true)) {
            $this->errors[] = sprintf(
                'Invalid mode "%s". Must be one of: %s.',
                $mode,
                implode(', ', self::VALID_MODES)
            );
        }
    }

    private function validateDriver(array $options): void
    {
        $driver = $options['driver'] ?? '';
        if (!in_array($driver, self::VALID_DRIVERS, true)) {
            $this->errors[] = sprintf(
                'Invalid driver "%s". Must be one of: pgsql, mysql, mariadb (or omit to infer from connection).',
                $driver
            );
        }
    }

    private function validateIncrementalOptions(array $options): void
    {
        $mode   = $options['mode'] ?? '';
        $driver = $options['driver'] ?? '';

        if ($mode !== 'incremental') {
            return;
        }

        // incremental-format
        $format = $options['incremental_format'] ?? 'csv';
        if (!in_array($format, self::VALID_INCREMENTAL_FORMATS, true)) {
            $this->errors[] = sprintf(
                'Invalid incremental-format "%s". Must be one of: %s.',
                $format,
                implode(', ', self::VALID_INCREMENTAL_FORMATS)
            );
        }

        // incremental-output
        $output = $options['incremental_output'] ?? 'separate';
        if (!in_array($output, self::VALID_INCREMENTAL_OUTPUTS, true)) {
            $this->errors[] = sprintf(
                'Invalid incremental-output "%s". Must be one of: %s.',
                $output,
                implode(', ', self::VALID_INCREMENTAL_OUTPUTS)
            );
        }

        // incremental-type for pgsql: only updated_at is supported
        if ($driver === 'pgsql') {
            $type = $options['incremental_type'] ?? '';
            if ($type !== '' && $type !== 'updated_at') {
                $this->errors[] = sprintf(
                    'PostgreSQL incremental supports only incremental-type=updated_at (got "%s").',
                    $type
                );
            }
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $type = $options['incremental_type'] ?? '';
            if ($type !== '' && !in_array($type, ['binlog', 'updated_at'], true)) {
                $this->errors[] = sprintf(
                    'MySQL incremental supports only incremental-type=binlog or updated_at (got "%s").',
                    $type
                );
            }
        }
    }

    private function validateDateRange(array $options): void
    {
        $from = $options['from_date'] ?? null;
        $to   = $options['to_date'] ?? null;

        if ($from === null || $to === null) {
            return;
        }

        $fromTs = strtotime((string)$from);
        $toTs   = strtotime((string)$to);

        if ($fromTs === false) {
            $this->errors[] = sprintf('Invalid from-date value: "%s".', $from);
            return;
        }

        if ($toTs === false) {
            $this->errors[] = sprintf('Invalid to-date value: "%s".', $to);
            return;
        }

        if ($fromTs > $toTs) {
            $this->errors[] = sprintf(
                'from-date (%s) must not be later than to-date (%s).',
                $from,
                $to
            );
        }
    }

    private function validateRetention(array $options): void
    {
        $keep = $options['retention_keep'] ?? null;
        $days = $options['retention_days'] ?? null;

        if ($keep !== null && $keep < 1) {
            $this->errors[] = 'retention-keep must be a positive integer (≥ 1).';
        }

        if ($days !== null && $days < 1) {
            $this->errors[] = 'retention-days must be a positive integer (≥ 1).';
        }
    }
}

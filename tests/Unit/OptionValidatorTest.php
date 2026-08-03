<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tekkenking\Dbbackupman\Support\OptionParser;
use Tekkenking\Dbbackupman\Support\OptionValidator;

class OptionValidatorTest extends TestCase
{
    private OptionValidator $validator;
    private OptionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new OptionValidator();
        $this->parser    = new OptionParser();
    }

    // ── mode ──────────────────────────────────────────────────────────────

    public function test_valid_mode_full_passes(): void
    {
        $opts = $this->parser->parse(['mode' => 'full']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_valid_mode_schema_passes(): void
    {
        $opts = $this->parser->parse(['mode' => 'schema']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_valid_mode_incremental_passes(): void
    {
        $opts = $this->parser->parse(['mode' => 'incremental']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_invalid_mode_fails(): void
    {
        $opts = $this->parser->parse(['mode' => 'partial']);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('Invalid mode', $v->firstError());
    }

    public function test_empty_mode_fails(): void
    {
        $opts = $this->parser->parse(['mode' => '']);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
    }

    // ── driver ────────────────────────────────────────────────────────────

    public function test_valid_driver_pgsql_passes(): void
    {
        $opts = $this->parser->parse(['mode' => 'full', 'driver' => 'pgsql']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_valid_driver_mysql_passes(): void
    {
        $opts = $this->parser->parse(['mode' => 'full', 'driver' => 'mysql']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_valid_driver_mariadb_passes(): void
    {
        $opts = $this->parser->parse(['mode' => 'full', 'driver' => 'mariadb']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_empty_driver_passes(): void
    {
        // Driver can be omitted (inferred from connection)
        $opts = $this->parser->parse(['mode' => 'full', 'driver' => '']);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_invalid_driver_fails(): void
    {
        $opts = $this->parser->parse(['mode' => 'full', 'driver' => 'sqlite']);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('Invalid driver', $v->firstError());
    }

    // ── incremental: pgsql type constraint ────────────────────────────────

    public function test_pgsql_incremental_with_updated_at_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'             => 'incremental',
            'driver'           => 'pgsql',
            'incremental-type' => 'updated_at',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_pgsql_incremental_without_type_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'   => 'incremental',
            'driver' => 'pgsql',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_pgsql_incremental_with_binlog_fails(): void
    {
        $opts = $this->parser->parse([
            'mode'             => 'incremental',
            'driver'           => 'pgsql',
            'incremental-type' => 'binlog',
        ]);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('PostgreSQL incremental', $v->firstError());
    }

    public function test_mysql_incremental_with_binlog_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'             => 'incremental',
            'driver'           => 'mysql',
            'incremental-type' => 'binlog',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_mysql_incremental_with_updated_at_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'             => 'incremental',
            'driver'           => 'mysql',
            'incremental-type' => 'updated_at',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_mysql_incremental_with_unknown_type_fails(): void
    {
        $opts = $this->parser->parse([
            'mode'             => 'incremental',
            'driver'           => 'mysql',
            'incremental-type' => 'typo',
        ]);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('MySQL incremental', $v->firstError());
    }

    // ── incremental-format and incremental-output ─────────────────────────

    public function test_invalid_incremental_format_fails(): void
    {
        $opts = $this->parser->parse([
            'mode'               => 'incremental',
            'incremental-format' => 'json',
        ]);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('incremental-format', $v->firstError());
    }

    public function test_invalid_incremental_output_fails(): void
    {
        $opts = $this->parser->parse([
            'mode'               => 'incremental',
            'incremental-output' => 'stream',
        ]);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('incremental-output', $v->firstError());
    }

    // ── date range ────────────────────────────────────────────────────────

    public function test_valid_date_range_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'      => 'full',
            'from-date' => '2025-01-01',
            'to-date'   => '2025-12-31',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_inverted_date_range_fails(): void
    {
        $opts = $this->parser->parse([
            'mode'      => 'full',
            'from-date' => '2025-12-31',
            'to-date'   => '2025-01-01',
        ]);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('from-date', $v->firstError());
    }

    public function test_equal_dates_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'      => 'full',
            'from-date' => '2025-06-01',
            'to-date'   => '2025-06-01',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    // ── retention ─────────────────────────────────────────────────────────

    public function test_valid_retention_passes(): void
    {
        $opts = $this->parser->parse([
            'mode'            => 'full',
            'retention-keep'  => '5',
            'retention-days'  => '30',
        ]);
        $this->assertTrue($this->validator->validate($opts)->passes());
    }

    public function test_zero_retention_keep_fails(): void
    {
        $opts = $this->parser->parse(['mode' => 'full', 'retention-keep' => '0']);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('retention-keep', $v->firstError());
    }

    public function test_zero_retention_days_fails(): void
    {
        $opts = $this->parser->parse(['mode' => 'full', 'retention-days' => '0']);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('retention-days', $v->firstError());
    }

    // ── multiple errors ───────────────────────────────────────────────────

    public function test_multiple_errors_collected(): void
    {
        $opts = $this->parser->parse([
            'mode'           => 'invalid',
            'driver'         => 'sqlite',
            'retention-keep' => '0',
        ]);
        $v = $this->validator->validate($opts);
        $this->assertTrue($v->fails());
        $this->assertGreaterThan(1, count($v->errors()));
    }
}

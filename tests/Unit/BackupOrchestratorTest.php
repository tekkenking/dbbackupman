<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tekkenking\Dbbackupman\Application\BackupContext;
use Tekkenking\Dbbackupman\Application\BackupOrchestrator;

class BackupOrchestratorTest extends TestCase
{
    // ── driver routing ────────────────────────────────────────────────────

    public function test_routes_to_pgsql_driver(): void
    {
        $called  = false;
        $drivers = [
            'pgsql' => function (BackupContext $ctx) use (&$called): array {
                $called = true;
                return ['driver' => 'pgsql', 'artifacts' => []];
            },
        ];

        $orchestrator = new BackupOrchestrator($drivers);
        $context      = new BackupContext(
            connection: 'pgsql',
            driver: 'pgsql',
            mode: 'full',
            gzip: false,
            outputDir: '/tmp',
        );

        $result = $orchestrator->run($context);

        $this->assertTrue($called);
        $this->assertSame('pgsql', $result['driver']);
    }

    public function test_routes_to_mysql_driver(): void
    {
        $called  = false;
        $drivers = [
            'mysql' => function (BackupContext $ctx) use (&$called): array {
                $called = true;
                return ['driver' => 'mysql', 'artifacts' => []];
            },
        ];

        $orchestrator = new BackupOrchestrator($drivers);
        $context      = new BackupContext(
            connection: 'mysql',
            driver: 'mysql',
            mode: 'full',
            gzip: false,
            outputDir: '/tmp',
        );

        $result = $orchestrator->run($context);

        $this->assertTrue($called);
        $this->assertSame('mysql', $result['driver']);
    }

    public function test_passes_context_to_driver(): void
    {
        $receivedContext = null;
        $drivers = [
            'pgsql' => function (BackupContext $ctx) use (&$receivedContext): array {
                $receivedContext = $ctx;
                return [];
            },
        ];

        $orchestrator = new BackupOrchestrator($drivers);
        $context      = new BackupContext(
            connection: 'myconn',
            driver: 'pgsql',
            mode: 'schema',
            gzip: true,
            outputDir: '/backups',
            options: ['per_schema' => true],
        );

        $orchestrator->run($context);

        $this->assertSame($context, $receivedContext);
        $this->assertSame('schema', $receivedContext->mode);
        $this->assertTrue($receivedContext->gzip);
    }

    public function test_throws_for_unsupported_driver(): void
    {
        $drivers = [
            'pgsql' => fn (BackupContext $ctx): array => [],
        ];

        $orchestrator = new BackupOrchestrator($drivers);
        $context      = new BackupContext(
            connection: 'sqlite',
            driver: 'sqlite',
            mode: 'full',
            gzip: false,
            outputDir: '/tmp',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: sqlite');

        $orchestrator->run($context);
    }

    public function test_throws_with_no_drivers_registered(): void
    {
        $orchestrator = new BackupOrchestrator([]);
        $context      = new BackupContext(
            connection: 'pgsql',
            driver: 'pgsql',
            mode: 'full',
            gzip: false,
            outputDir: '/tmp',
        );

        $this->expectException(InvalidArgumentException::class);

        $orchestrator->run($context);
    }

    public function test_returns_driver_result(): void
    {
        $expected = ['artifacts' => ['/tmp/dump.sql'], 'manifest' => ['files' => ['dump.sql']]];
        $drivers  = [
            'pgsql' => fn (BackupContext $ctx): array => $expected,
        ];

        $orchestrator = new BackupOrchestrator($drivers);
        $context      = new BackupContext(
            connection: 'pgsql',
            driver: 'pgsql',
            mode: 'full',
            gzip: false,
            outputDir: '/tmp',
        );

        $result = $orchestrator->run($context);

        $this->assertSame($expected, $result);
    }
}

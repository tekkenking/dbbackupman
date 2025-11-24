<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Tekkenking\Dbbackupman\Services\Incremental\MySqlUpdatedAt;
use Tekkenking\Dbbackupman\Support\ProcessRunner;
use Mockery;

class MySqlUpdatedAtTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_parse_date_formats(): void
    {
        $runner = Mockery::mock(ProcessRunner::class);
        $strategy = new MySqlUpdatedAt($runner);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('parseDate');
        $method->setAccessible(true);
        
        // Test YYYY-MM-DD format
        $date = $method->invoke($strategy, '2025-11-01');
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertEquals('2025-11-01 00:00:00', $date->format('Y-m-d H:i:s'));
        
        // Test YYYY-MM-DD HH:MM:SS format
        $date = $method->invoke($strategy, '2025-11-01 14:30:45');
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertEquals('2025-11-01 14:30:45', $date->format('Y-m-d H:i:s'));
        
        // Test null returns null
        $date = $method->invoke($strategy, null);
        $this->assertNull($date);
    }

    public function test_parse_date_invalid_format_throws_exception(): void
    {
        $runner = Mockery::mock(ProcessRunner::class);
        $strategy = new MySqlUpdatedAt($runner);
        
        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('parseDate');
        $method->setAccessible(true);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format');
        
        $method->invoke($strategy, 'invalid-date');
    }

    public function test_filter_tables_with_include_patterns(): void
    {
        $runner = Mockery::mock(ProcessRunner::class);
        $strategy = new MySqlUpdatedAt($runner);
        
        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('filterTables');
        $method->setAccessible(true);
        
        $tables = ['orders', 'users', 'products', 'admin_logs'];
        
        // Include with wildcard
        $result = $method->invoke($strategy, $tables, ['order*'], []);
        $this->assertCount(1, $result);
        $this->assertEquals('orders', $result[0]);
        
        // Include specific tables
        $result = $method->invoke($strategy, $tables, ['orders', 'users'], []);
        $this->assertCount(2, $result);
        $this->assertContains('orders', $result);
        $this->assertContains('users', $result);
    }

    public function test_filter_tables_with_exclude_patterns(): void
    {
        $runner = Mockery::mock(ProcessRunner::class);
        $strategy = new MySqlUpdatedAt($runner);
        
        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('filterTables');
        $method->setAccessible(true);
        
        $tables = ['orders', 'users', 'products', 'admin_logs'];
        
        // Exclude admin tables
        $result = $method->invoke($strategy, $tables, [], ['admin_*']);
        $this->assertCount(3, $result);
        $this->assertNotContains('admin_logs', $result);
    }

    public function test_filter_tables_with_both_include_and_exclude(): void
    {
        $runner = Mockery::mock(ProcessRunner::class);
        $strategy = new MySqlUpdatedAt($runner);
        
        $reflection = new \ReflectionClass($strategy);
        $method = $reflection->getMethod('filterTables');
        $method->setAccessible(true);
        
        $tables = ['orders', 'users', 'products', 'admin_logs'];
        
        // Include all but exclude admin
        $result = $method->invoke($strategy, $tables, ['*'], ['admin_*']);
        $this->assertCount(3, $result);
        $this->assertNotContains('admin_logs', $result);
    }
}

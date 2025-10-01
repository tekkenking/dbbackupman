<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Tekkenking\Dbbackupman\Support\ProcessRunner;

class ProcessRunnerTest extends TestCase
{
    public function test_run_to_file(): void
    {
        $runner = new ProcessRunner();
        $tmp = sys_get_temp_dir().'/runner_test.txt';
        $runner->runToFile([PHP_BINARY, '-r', 'echo "hi";'], $tmp);
        $this->assertFileExists($tmp);
        $this->assertSame("hi", trim(file_get_contents($tmp)));
        unlink($tmp);
    }
}

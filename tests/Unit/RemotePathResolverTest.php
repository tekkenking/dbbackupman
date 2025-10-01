<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tekkenking\Dbbackupman\Support\RemotePathResolver;

class RemotePathResolverTest extends TestCase
{
    public function test_map_overrides_fallback(): void
    {
        $map = ['s3' => 'backups/prod', 'wasabi' => ''];
        $this->assertSame('backups/prod', RemotePathResolver::forDisk('s3', $map, 'fallback/x'));
        $this->assertSame('', RemotePathResolver::forDisk('wasabi', $map, 'fallback/x'));
        $this->assertSame('fallback/x', RemotePathResolver::forDisk('gcs', $map, 'fallback/x'));
    }
}

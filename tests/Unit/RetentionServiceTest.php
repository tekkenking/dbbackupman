<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tekkenking\Dbbackupman\Services\Retention\RetentionService;

class RetentionServiceTest extends TestCase
{
    public function test_keep_and_days_apply(): void
    {
        $svc = new RetentionService();
        $files = [
            'db_pg_full_20240101_010101.manifest.json',
            'db_pg_full_20240102_010101.manifest.json',
            'db_pg_full_20250101_010101.manifest.json',
        ];

        // Keep 1 should mark first two as victims (sorted asc)
        $victims = $svc->victims($files, 1, null);
        $this->assertSame(['20240101_010101','20240102_010101'], $victims);

        // Days: just ensure it returns an array
        $this->assertIsArray($svc->victims($files, null, 9999));
    }
}

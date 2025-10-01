<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

final class RemotePathResolver
{
    /**
     * @param array<string,string> $remoteMap disk => path (may be "")
     */
    public static function forDisk(string $disk, array $remoteMap, string $fallbackPlain): string
    {
        return array_key_exists($disk, $remoteMap) ? trim($remoteMap[$disk], '/') : trim($fallbackPlain, '/');
    }
}

<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Contracts;

interface Uploader
{
    /**
     * Upload local files to a given disk at base path (may be "").
     */
    public function upload(string $disk, string $basePath, array $localFiles): void;
}

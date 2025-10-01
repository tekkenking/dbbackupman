<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Uploader;

use Tekkenking\Dbbackupman\Contracts\Uploader;
use Illuminate\Support\Facades\Storage;

class StorageUploader implements Uploader
{
    public function upload(string $disk, string $basePath, array $localFiles): void
    {
        $prefix = $basePath !== '' ? trim($basePath, '/').'/' : '';
        $store  = Storage::disk($disk);
        foreach ($localFiles as $path) {
            $name = basename($path);
            $store->put($prefix.$name, fopen($path, 'r'));
        }
    }
}

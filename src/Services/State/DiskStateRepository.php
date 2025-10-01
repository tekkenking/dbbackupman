<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\State;

use Tekkenking\Dbbackupman\Contracts\StateRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DiskStateRepository implements StateRepository
{
    public function load(string $diskOrLocalKey, string $name): array
    {
        if ($diskOrLocalKey === 'local') {
            return File::exists($name) ? (json_decode(File::get($name), true) ?: []) : [];
        }
        $store = Storage::disk($diskOrLocalKey);
        return $store->exists($name) ? (json_decode($store->get($name), true) ?: []) : [];
    }

    public function save(string $diskOrLocalKey, string $name, array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($diskOrLocalKey === 'local') {
            File::ensureDirectoryExists(dirname($name));
            File::put($name, $json);
        } else {
            $store = Storage::disk($diskOrLocalKey);
            $store->put($name, $json);
        }
    }
}

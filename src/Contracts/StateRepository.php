<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Contracts;

interface StateRepository
{
    public function load(string $diskOrLocalKey, string $name): array;
    public function save(string $diskOrLocalKey, string $name, array $state): void;
}

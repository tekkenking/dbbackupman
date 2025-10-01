<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Contracts;

use Tekkenking\Dbbackupman\Support\ConnectionInfo;

interface IncrementalStrategy
{
    /**
     * @return array{artifacts: string[], manifest_meta: array}
     */
    public function run(ConnectionInfo $c, array $opt): array;
}

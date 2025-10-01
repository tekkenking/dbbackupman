<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Contracts;

use Tekkenking\Dbbackupman\Support\ConnectionInfo;

interface Dumper
{
    /**
     * @return array{artifacts: string[], manifest: array}
     */
    public function dump(ConnectionInfo $c, array $opt): array;
}

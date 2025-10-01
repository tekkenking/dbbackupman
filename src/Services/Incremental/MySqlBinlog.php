<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Incremental;

use Tekkenking\Dbbackupman\Contracts\IncrementalStrategy;
use Tekkenking\Dbbackupman\Support\ConnectionInfo;
use Tekkenking\Dbbackupman\Support\ProcessRunner;

class MySqlBinlog implements IncrementalStrategy
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function run(ConnectionInfo $c, array $opt): array
    {
        $pdo = new \PDO(
            "mysql:host={$c->host};port={$c->port};dbname={$c->database}",
            $c->username, $c->password ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $state = (array)($opt['state'] ?? []);           // ['file'=>..., 'pos'=>...]
        $gzip  = (bool)($opt['gzip'] ?? false);

        $status = $pdo->query('SHOW MASTER STATUS')->fetch(\PDO::FETCH_ASSOC) ?: [];
        $logs   = $pdo->query('SHOW BINARY LOGS')->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ((!$status || empty($status['File'])) && empty($logs)) {
            throw new \RuntimeException('Binary logs not enabled or not accessible.');
        }
        $currentFile = $status['File'] ?? $logs[array_key_last($logs)]['Log_name'];
        $currentPos  = (int)($status['Position'] ?? 4);

        $startFile = $state['file'] ?? $currentFile;
        $startPos  = isset($state['pos']) ? (int)$state['pos'] : 4;

        $names = array_values(array_filter(array_map(fn($r) => $r['Log_name'] ?? $r['File_name'] ?? null, $logs)));
        if (!in_array($startFile, $names, true)) $names[] = $startFile;
        if (!in_array($currentFile, $names, true)) $names[] = $currentFile;
        natsort($names); $names = array_values($names);

        $startIdx = array_search($startFile, $names, true);
        $endIdx   = array_search($currentFile, $names, true);
        if ($startIdx === false || $endIdx === false) {
            $startIdx = $endIdx = array_search($currentFile, $names, true);
        }

        $binPath = rtrim($c->workdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            "{$c->database}_my_incremental_{$c->timestamp}" . ($c->noteTag ?? '') . ".binlog.sql";

        $fh = fopen($binPath, 'w');
        if (!$fh) throw new \RuntimeException("Cannot open $binPath for writing");

        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $file = $names[$i];
            $cmd = [
                $c->tools['mysqlbinlog'],
                '--read-from-remote-server',
                '--host='.$c->host,
                '--port='.$c->port,
                '--user='.$c->username,
                '--password='.($c->password ?? ''),
                '--verbose',
            ];
            if ($i === $startIdx) $cmd[] = '--start-position='.$startPos;
            $cmd[] = $file;

            $p = new \Symfony\Component\Process\Process($cmd);
            $p->setTimeout(null)->run(function ($type, $buffer) use ($fh) {
                if ($type === \Symfony\Component\Process\Process::OUT) fwrite($fh, $buffer);
                else fwrite(STDERR, $buffer);
            });
            if (!$p->isSuccessful()) {
                fclose($fh);
                throw new \RuntimeException("mysqlbinlog failed for $file: ".$p->getErrorOutput());
            }
        }
        fclose($fh);

        if ($gzip) $binPath = $this->runner->gzip($binPath);

        return [
            'artifacts' => [$binPath],
            'manifest_meta' => [
                'incremental_type' => 'binlog',
                'from' => $state ?: ['file'=>$startFile,'pos'=>$startPos],
                'to'   => ['file'=>$currentFile,'pos'=>$currentPos],
            ],
        ];
    }
}

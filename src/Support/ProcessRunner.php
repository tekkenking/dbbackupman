<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    public function run(array $cmd, array $env = [], ?callable $onErrStream = null): void
    {
        $p = new Process($cmd, null, $env);
        $p->setTimeout(null)->run(function ($type, $buffer) use ($onErrStream) {
            if ($type === Process::ERR && $onErrStream) {
                $onErrStream($buffer);
            }
        });

        if (!$p->isSuccessful()) {
            throw new RuntimeException("Command failed: " . implode(' ', $cmd) . "\n" . $p->getErrorOutput());
        }
    }

    public function runToFile(array $cmd, string $path, array $env = [], ?callable $onErrStream = null): void
    {
        $fh = fopen($path, 'w');
        if (!$fh) {
            throw new RuntimeException("Cannot open $path for writing.");
        }

        $p = new Process($cmd, null, $env);
        $p->setTimeout(null);
        $p->run(function ($type, $buffer) use ($fh, $onErrStream) {
            if ($type === Process::OUT) {
                fwrite($fh, $buffer);
            } else {
                if ($onErrStream) {
                    $onErrStream($buffer);
                }
            }
        });
        fclose($fh);

        if (!$p->isSuccessful()) {
            throw new RuntimeException("Command failed: " . implode(' ', $cmd) . "\n" . $p->getErrorOutput());
        }
    }

    public function gzip(string $path): string
    {
        $gz = $path . '.gz';
        $in = fopen($path, 'rb');
        $out = gzopen($gz, 'wb9');
        while (!feof($in)) {
            gzwrite($out, fread($in, 262144));
        }
        gzclose($out);
        fclose($in);
        return $gz;
    }
}

<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Services\Retention;

use Carbon\CarbonImmutable;

class RetentionService
{
    /**
     * @param array<string> $manifestFilenames Sorted list of manifest names
     * @return array<string> tokens like "20250929_153501" or "20250929_153501_note"
     */
    public function victims(array $manifestFilenames, ?int $keep, ?int $days): array
    {
        $items = [];
        foreach ($manifestFilenames as $name) {
            if (preg_match('/_(\d{8}_\d{6}(?:_[A-Za-z0-9._-]+)?)\.manifest\.json$/', $name, $m)) {
                $token = $m[1]; $ts = substr($token, 0, 15);
                $items[] = ['token'=>$token, 'ts'=>$ts];
            }
        }
        usort($items, fn($a,$b) => strcmp($a['ts'],$b['ts']));

        $victims=[];
        if ($days !== null) {
            $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Ymd_His');
            foreach ($items as $it) if ($it['ts'] < $cutoff) $victims[$it['token']] = true;
        }
        if ($keep !== null && $keep >= 0) {
            $excess = max(0, count($items) - $keep);
            for ($i=0; $i<$excess; $i++) $victims[$items[$i]['token']] = true;
        }
        return array_keys($victims);
    }
}

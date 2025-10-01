<?php
declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Thin wrapper to invoke the db:backup command programmatically.
 */
class DbBackupmanManager
{
    /**
     * Run the backup with the same options you'd pass on the CLI.
     *
     * Example:
     *   DbBackupman::run([
     *     'connection' => 'pgsql',
     *     'mode' => 'full',
     *     'gzip' => true,
     *     'disks' => 's3,wasabi',
     *     'remote-map' => '{"s3":"backups/prod","wasabi":""}',
     *   ]);
     *
     * @param array<string, scalar|bool|null> $options
     * @return int artisan exit code
     */
    public function run(array $options = []): int
    {
        $args = [];
        foreach ($options as $key => $value) {
            $flag = '--' . $key;
            if (is_bool($value)) {
                if ($value) $args[$flag] = true; // include truthy flags; omit false
            } elseif ($value !== null) {
                $args[$flag] = (string) $value;
            }
        }
        return Artisan::call('db:backup', $args);
    }
}

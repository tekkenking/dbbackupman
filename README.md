# DbBackupman

Cross-DB backups for Laravel (PostgreSQL/MySQL/MariaDB) with uploads, incremental, retention, and per-disk remote paths.

- Laravel 10/11/12
- PHP 8.1/8.2/8.3

## Install

```bash
composer require tekkenking/dbbackupman
php artisan vendor:publish --provider="Tekkenking\Dbbackupman\DbBackupServiceProvider" --tag=config
```

## Quick start
```bash
php artisan db:backup \
--connection=pgsql --mode=full --gzip \
--disks=s3 \
--remote-map='{"s3":"backups/prod"}' \
--retention-keep=7 --retention-days=30
```

### Facade
```php
use Tekkenking\Dbbackupman\Facades\DbBackupman;

DbBackupman::run([
  'connection' => 'mysql',
  'mode'       => 'incremental',
  'gzip'       => true,
  'disks'      => 's3',
  'remote-path'=> 'backups/mysql/incr',
]);
```


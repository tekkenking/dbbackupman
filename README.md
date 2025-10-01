# DbBackupman

Cross-DB backups for Laravel with uploads, incremental modes, retention, and per-disk remote paths.

* **Databases:** PostgreSQL, MySQL, MariaDB
* **Modes:** `full`, `schema`, `incremental`
* **Uploads:** Multiple filesystem disks; per-disk remote paths (empty path → bucket root)
* **Retention:** Keep N latest sets and/or delete sets older than D days
* **Laravel:** 10 · 11 · 12 (PHP 8.1+)

`composer` package: **`tekkenking/dbbackupman`**
Namespace: **`Tekkenking\Dbbackupman`**

---

## Table of Contents

* [Requirements](#requirements)
* [Install](#install)
* [Configuration](#configuration)
* [Command Usage](#command-usage)
* [Examples](#examples)
* [Uploads: Per-Disk Remote Paths](#uploads-per-disk-remote-paths)
* [Incremental Backups & State](#incremental-backups--state)
* [Restoring Backups](#restoring-backups)
* [Scheduling](#scheduling)
* [Using the Facade](#using-the-facade)
* [Testing the Package](#testing-the-package)
* [Troubleshooting](#troubleshooting)
* [Version Compatibility](#version-compatibility)
* [Security Notes](#security-notes)
* [Contributing](#contributing)
* [License](#license)

---

## Requirements

* PHP **8.1+** (8.2/8.3 recommended)
* Laravel **10 / 11 / 12**
* DB client tools available on the host that runs the command:

    * PostgreSQL: `pg_dump`, `psql` (and `pg_dumpall` if using `--globals`)
    * MySQL/MariaDB: `mysqldump`, `mysqlbinlog` (for incremental)
* Properly configured Laravel **database connection(s)** and **filesystem disk(s)**

> The command preflights required tools and will error early if a binary is missing.

---

## Install

```bash
composer require tekkenking/dbbackupman
php artisan vendor:publish --provider="Tekkenking\Dbbackupman\DbBackupServiceProvider" --tag=config
```

This publishes `config/dbbackup.php`.

---

## Configuration

`config/dbbackup.php`:

```php
<?php

return [
    'tools' => [
        'pg_dump'     => env('DBBACKUP_PG_DUMP', 'pg_dump'),
        'pg_dumpall'  => env('DBBACKUP_PG_DUMPALL', 'pg_dumpall'),
        'psql'        => env('DBBACKUP_PSQL', 'psql'),
        'mysqldump'   => env('DBBACKUP_MYSQLDUMP', 'mysqldump'),
        'mysqlbinlog' => env('DBBACKUP_MYSQLBINLOG', 'mysqlbinlog'),
    ],
    'upload' => [
        'disks'       => [],   // e.g. ['s3','wasabi']
        'remote_path' => '',   // fallback base path when disk not in remote_map
        'remote_map'  => [     // per-disk base path; "" means bucket root
            // 's3' => 'backups/prod',
            // 'wasabi' => '',
        ],
    ],
    'retention' => [
        'keep' => null,  // keep last N sets
        'days' => null,  // delete sets older than D days
    ],
];
```

Define your filesystem disks in `config/filesystems.php` (e.g., S3/Wasabi/GCS).

---

## Command Usage

Primary entry:

```bash
php artisan db:backup [options]
```

**Core options**

* `--connection=` Laravel DB connection name (defaults to `database.default`)
* `--driver=` Force `pgsql` or `mysql`/`mariadb` (usually inferred from the connection)
* `--mode=full|schema|incremental`
* `--gzip` Compress outputs (`.gz`)
* `--out=` Local output dir (default: `storage/app/db-backups`)

**Uploads**

* `--disks=` CSV list of filesystem disks (e.g. `s3,wasabi`). If omitted, uses `config('dbbackup.upload.disks')`.
* `--remote-path=` Fallback base path for disks not in the map (can be empty to upload at bucket root)
* `--remote-map=` Per-disk base path; JSON or CSV:

    * JSON: `--remote-map='{"s3":"backups/prod","wasabi":""}'`
    * CSV : `--remote-map=s3:backups/prod,wasabi:`

**Retention**

* `--retention-keep=` Keep latest N backup sets
* `--retention-days=` Delete sets older than D days
  *Applied to local and each remote disk using manifest timestamps.*

**PostgreSQL-only**

* `--per-schema` Dump each non-system schema individually
* `--include=` CSV schemas to include (with `--per-schema`)
* `--exclude=` CSV schemas to exclude (with `--per-schema`)
* `--globals` Include roles & tablespaces via `pg_dumpall -g`
* `--no-owner` Omit ownership
* Incremental:

    * `--since=` ISO8601 start time (fallback when no state found)
    * `--pg-csv-include=` CSV table patterns (`schema.table`, `*` allowed)
    * `--pg-csv-exclude=` CSV table patterns

**State (incremental)**

* `--state-disk=` Disk used to store state (default: first upload disk; else local)
* `--state-path=` Base path for state JSON (default: `{diskBase}/_state` or `_state` if base is empty)

---

## Examples

### PostgreSQL — full dump to S3 + Wasabi; keep 7, purge >30 days

```bash
php artisan db:backup \
  --connection=pgsql \
  --mode=full --gzip \
  --disks=s3,wasabi \
  --remote-map='{"s3":"backups/prod","wasabi":""}' \
  --retention-keep=7 --retention-days=30
```

### PostgreSQL — schema-only with per-schema dumps

```bash
php artisan db:backup \
  --connection=pgsql \
  --mode=schema --per-schema --gzip \
  --exclude=pg_temp,pg_toast \
  --disks=s3 \
  --remote-path=backups/pg/schema
```

### PostgreSQL — incremental via `updated_at` CSVs

```bash
php artisan db:backup \
  --connection=pgsql \
  --mode=incremental --gzip \
  --pg-csv-include=public.orders,public.users \
  --disks=s3 \
  --remote-path=backups/pg/incr
```

> Produces one CSV per table with rows where `updated_at > since`.
> Does **not** capture deletes or schema changes — pair with periodic full backups.

### MySQL/MariaDB — full dump

```bash
php artisan db:backup \
  --connection=mysql \
  --mode=full --gzip \
  --disks=s3 \
  --remote-path=backups/mysql/full
```

### MySQL/MariaDB — incremental via binlog

```bash
php artisan db:backup \
  --connection=mysql \
  --mode=incremental --gzip \
  --disks=s3 \
  --remote-path=backups/mysql/incr
```

**MySQL/MariaDB incremental prerequisites**

`my.cnf`:

```
server_id=1
log_bin=/var/log/mysql/mysql-bin
binlog_format=ROW
```

Grant the backup user `REPLICATION CLIENT` so `SHOW MASTER STATUS` / `SHOW BINARY LOGS` work.
Ensure `mysqlbinlog` is installed on the host running the command.

---

## Uploads: Per-Disk Remote Paths

Set via `--remote-map` (preferred) or `config('dbbackup.upload.remote_map')`.

* Disk **in** the map → use its mapped base path.
* Disk **not** in the map → use `--remote-path` (or `config('dbbackup.upload.remote_path')`).
* Path value `""` (empty) → upload to bucket **root**.

**Examples**

* JSON: `--remote-map='{"s3":"backups/prod","wasabi":""}'`
* CSV : `--remote-map=s3:backups/prod,wasabi:`

---

## Incremental Backups & State

DbBackupman saves a small **state JSON** so the next incremental knows where to resume.

* **MySQL/MariaDB**: `{conn}_mysql_state.json` → `{"file":"mysql-bin.000123","pos":45678}`
* **PostgreSQL**: `{conn}_pgsql_state.json` → `{"since_utc":"2025-09-30T10:00:00Z"}`

**Where is state stored?**

* If `--state-disk` is provided (or defaults to the first upload disk), state is stored **on that disk**, under `--state-path` (default: `{diskBase}/_state`, or `_state` if base is empty).
* If no disks are used, state is stored **locally** at `storage/app/db-backups/_state`.

**Examples**

* Disk base `backups/prod` → `backups/prod/_state/{conn}_mysql_state.json`
* Disk base `""` (root) → `_state/{conn}_mysql_state.json`

---

## Restoring Backups

### PostgreSQL (full/schema)

Files end in `.dump` (custom format). Restore with `pg_restore`:

```bash
# create DB if needed
createdb -h HOST -U USER TARGET_DB

# restore
pg_restore -h HOST -U USER -d TARGET_DB -1 app_db_pg_full_YYYYMMDD_HHMMSS.dump

# if gzipped:
pg_restore -h HOST -U USER -d TARGET_DB -1 <(gzcat app_db_pg_full_YYYYMMDD_HHMMSS.dump.gz)
```

If you used `--globals`, apply roles/tablespaces first:

```bash
psql -h HOST -U USER -d postgres -f globals_pg_YYYYMMDD_HHMMSS.sql
```

### MySQL/MariaDB (full/schema)

```bash
mysql -h HOST -u USER -p DB_NAME < app_db_my_full_YYYYMMDD_HHMMSS.sql

# gz:
gzcat app_db_my_full_YYYYMMDD_HHMMSS.sql.gz | mysql -h HOST -u USER -p DB_NAME
```

### Incremental

* **PostgreSQL CSV:** Load into staging tables then **upsert** into targets using primary keys.

  ```sql
  \copy staging_orders FROM 'public_orders.csv' CSV HEADER;
  -- MERGE / UPSERT into public.orders
  ```

  *Note:* CSV incremental does **not** capture deletes — schedule periodic full backups.

* **MySQL/MariaDB binlog SQL:** Apply directly:

  ```bash
  mysql -h HOST -u USER -p DB_NAME < app_db_my_incremental_YYYYMMDD_HHMMSS.binlog.sql
  ```

  Ensure GTID/binlog settings align with your environment; treat carefully if replication is used.

---

## Scheduling

### Laravel scheduler (`app/Console/Kernel.php`)

```php
protected function schedule(Schedule $schedule): void
{
    // Nightly full (keep 7, 30 days)
    $schedule->command('db:backup --connection=pgsql --mode=full --gzip --disks=s3 --remote-map={"s3":"backups/prod"} --retention-keep=7 --retention-days=30')
             ->dailyAt('01:30')->withoutOverlapping()->onOneServer();

    // Hourly incremental (PG)
    $schedule->command('db:backup --connection=pgsql --mode=incremental --pg-csv-include=public.orders,public.users --disks=s3 --remote-path=backups/pg/incr')
             ->hourly()->withoutOverlapping()->onOneServer();
}
```
---

## Using the Facade

You can invoke backups from code with a simple Facade:

```php
use Tekkenking\Dbbackupman\Facades\DbBackupman;

// Full
DbBackupman::run([
  'connection'     => 'pgsql',
  'mode'           => 'full',
  'gzip'           => true,
  'disks'          => 's3',
  'remote-map'     => '{"s3":"backups/prod"}',
  'retention-keep' => 7,
  'retention-days' => 30,
]);

// MySQL incremental with state on another disk
DbBackupman::run([
  'connection'  => 'mysql',
  'mode'        => 'incremental',
  'disks'       => 's3',
  'remote-path' => 'backups/mysql/incr',
  'state-disk'  => 'wasabi',
  'state-path'  => 'backup-state',
]);
```

> Options mirror CLI flags **without** the leading `--`.
> Booleans: pass `true` to include the flag (e.g., `'gzip' => true`).

**Optional global alias** (so you can call `DbBackupman::run()` without an import):

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Foundation\AliasLoader;

public function boot(): void
{
    AliasLoader::getInstance()->alias(
        'DbBackupman',
        \Tekkenking\Dbbackupman\Facades\DbBackupman::class
    );
}
```

---

## Testing the Package

This repo is set up for **Orchestra Testbench**.

```bash
composer install
vendor/bin/phpunit
```

If you see version conflicts with Laravel/Testbench/PHPUnit, align versions (e.g., Testbench 10 for Laravel 12).
A CI matrix can test PHP 8.1–8.3 × Laravel 10/11/12.

---

## Troubleshooting

* **“Required tool not found…”**
  Install the DB client tools your mode needs (`pg_dump`, `psql`, `pg_dumpall`, `mysqldump`, `mysqlbinlog`).

* **MySQL incremental says binary logs disabled**
  Verify `log_bin` and `binlog_format=ROW` in `my.cnf`. Ensure your user has `REPLICATION CLIENT`.

* **Uploads land in the wrong folder**
  Check `--remote-map` vs `--remote-path`. Empty path (`""`) means **bucket root**.

* **Retention didn’t delete old sets**
  Retention scans **manifest filenames** locally and per disk. Ensure manifests are present.

* **PostgreSQL incremental CSVs are empty**
  No rows matched `updated_at > since`. Validate `--since`/state and table patterns.

---

## Version Compatibility

| Laravel |  PHP | Testbench | PHPUnit |
| :-----: | :--: | :-------: | :-----: |
|   10.x  | ≥8.1 |    ^8.0   |  ^10.5  |
|   11.x  | ≥8.2 |    ^9.0   |  ^10.5  |
|   12.x  | ≥8.2 |   ^10.0   |  ^11.x  |

---

## Security Notes

* **Secrets on CLI:** MySQL tools receive `--password=...`, which may be visible to local process lists. Run on trusted hosts. (Postgres uses `PGPASSWORD` env for `pg_dump`/`psql`.)
* Lock down `storage/app/db-backups` permissions.
* Use least-privilege DB accounts suitable for backups.

---

## Contributing

PRs and issues welcome! Please run tests locally:

```bash
composer test
# or
vendor/bin/phpunit
```

If you’re adding behavior, include/adjust tests where appropriate.

---

## License

MIT. See [LICENSE](LICENSE).

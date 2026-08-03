# DbBackupman

Cross-DB backups for Laravel with uploads, incremental modes, retention, and per-disk remote paths.

* **Databases:** PostgreSQL, MySQL, MariaDB
* **Modes:** `full`, `schema`, `incremental`
* **Uploads:** Multiple filesystem disks; per-disk remote paths (empty path → bucket root)
* **Retention:** Keep N latest sets and/or delete sets older than D days
* **Laravel:** 10 · 11 · 12 · 13 (PHP 8.2+)

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

* PHP **8.2+** (8.2–8.5 supported)
* Laravel **10 / 11 / 12**
* DB client tools available on the host that runs the command:

    * PostgreSQL: `pg_dump`, `psql` (and `pg_dumpall` if using `--globals`)
    * MySQL/MariaDB: `mysqldump`; `mysqlbinlog` (for `incremental` binlog mode); `mysql` client (for `incremental` `updated_at` mode)
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
        'mysql'       => env('DBBACKUP_MYSQL', 'mysql'),
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

    // When true, keeps the uncompressed file alongside the .gz when --gzip is used.
    // Set DBBACKUP_KEEP_RAW=true in .env to enable.
    'keep_raw' => env('DBBACKUP_KEEP_RAW', false),
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

**MySQL-only**

* Incremental:

    * `--incremental-type=binlog|updated_at` Type of incremental (default: binlog)
    * `--mysql-csv-include=` CSV of tables to include (for `updated_at` type)
    * `--mysql-csv-exclude=` CSV of tables to exclude (for `updated_at` type)

**Incremental options (PostgreSQL and MySQL `updated_at` type)**

* `--from-date=` Start date for incremental exports (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
* `--to-date=` End date for incremental exports (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, defaults to now)
* `--incremental-format=csv|sql` Format for incremental exports (default: csv)
* `--incremental-output=separate|combined` Output style: separate (one file per table) or combined (single file) (default: separate)

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

### PostgreSQL — incremental with date range and SQL format

```bash
# SQL format, separate files, specific date range
php artisan db:backup \
  --connection=pgsql \
  --mode=incremental \
  --from-date="2025-11-01" \
  --to-date="2025-11-24" \
  --incremental-format=sql \
  --incremental-output=separate \
  --pg-csv-include=public.orders,public.users \
  --gzip \
  --disks=s3

# CSV format, combined file
php artisan db:backup \
  --connection=pgsql \
  --mode=incremental \
  --from-date="2025-11-01 00:00:00" \
  --incremental-format=csv \
  --incremental-output=combined \
  --pg-csv-include=public.* \
  --disks=s3
```

**New incremental options:**

* `--from-date=` Start date for exports (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
* `--to-date=` End date for exports (defaults to now)
* `--incremental-format=csv|sql` Export format (default: csv)
* `--incremental-output=separate|combined` Output style (default: separate)

### MySQL/MariaDB — full dump

```bash
php artisan db:backup \
  --connection=mysql \
  --mode=full --gzip \
  --disks=s3 \
  --remote-path=backups/mysql/full
```

### MySQL/MariaDB — incremental via binlog (default)

```bash
php artisan db:backup \
  --connection=mysql \
  --mode=incremental --gzip \
  --disks=s3 \
  --remote-path=backups/mysql/incr
```

### MySQL/MariaDB — incremental via `updated_at` (new!)

```bash
# SQL format, separate files per table
php artisan db:backup \
  --connection=mysql \
  --mode=incremental \
  --incremental-type=updated_at \
  --from-date="2025-11-01" \
  --to-date="2025-11-24" \
  --incremental-format=sql \
  --incremental-output=separate \
  --mysql-csv-include=orders,users,products \
  --gzip \
  --disks=s3

# CSV format, combined file
php artisan db:backup \
  --connection=mysql \
  --mode=incremental \
  --incremental-type=updated_at \
  --from-date="2025-11-01 00:00:00" \
  --incremental-format=csv \
  --incremental-output=combined \
  --mysql-csv-include=orders,users \
  --disks=s3
```

**MySQL incremental options:**

* `--incremental-type=binlog|updated_at` Type of incremental (default: binlog)
* `--mysql-csv-include=` CSV of tables to include (for updated_at type)
* `--mysql-csv-exclude=` CSV of tables to exclude (for updated_at type)

**MySQL/MariaDB incremental prerequisites (binlog only)**

`my.cnf`:

```
server_id=1
log_bin=/var/log/mysql/mysql-bin
binlog_format=ROW
```

Grant the backup user `REPLICATION CLIENT` so `SHOW MASTER STATUS` / `SHOW BINARY LOGS` work.
Ensure `mysqlbinlog` is installed on the host running the command.

---

## Incremental Export Formats: CSV vs SQL

When using `--mode=incremental` with the `updated_at` strategy (PostgreSQL or MySQL), you can choose between CSV and SQL output formats:

### CSV Format (default)

**Pros:**
* Smaller file size
* Faster to generate
* Easy to import into staging tables
* Works well with data analysis tools

**Cons:**
* Requires manual UPSERT logic to merge into target tables
* Doesn't include SQL structure or metadata
* Need to handle primary key conflicts manually

**Use when:**
* Loading into staging tables for manual review
* Exporting data for analysis or ETL pipelines
* File size and speed are priorities

### SQL Format

**Pros:**
* Ready-to-execute INSERT statements
* Can be applied directly with `mysql` or `psql`
* Includes proper quoting and escaping
* Self-documenting (shows table structure in comments)

**Cons:**
* Larger file size (SQL overhead)
* Slower to generate
* May have duplicate key conflicts if reapplied

**Use when:**
* Direct application to target database
* Need human-readable restore scripts
* Automation/replication scenarios

### Output Modes

**Separate (default):**
* One file per table: `schema_table1.sql`, `schema_table2.sql`
* Easier to selectively restore individual tables
* Better for large datasets (parallel processing)

**Combined:**
* Single file with all tables: `database_my_incremental_20251124.sql`
* Simpler to manage (one file to track)
* Wrapped in transaction (BEGIN/COMMIT)

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

DbBackupman supports different incremental strategies with different state management:

### MySQL/MariaDB Binlog (stateful)

Uses `{conn}_mysql_state.json` → `{"file":"mysql-bin.000123","pos":45678}`

State is automatically saved and used to resume from the last position.

### PostgreSQL/MySQL updated_at (date-range)

When using `--from-date` and `--to-date`, backups are based on explicit date ranges and don't use state files. This is ideal for:

* One-time exports of historical data
* Backfilling specific time periods
* Parallel exports of different date ranges

When using the default behavior (no date parameters), PostgreSQL saves: `{conn}_pgsql_state.json` → `{"since_utc":"2025-09-30T10:00:00Z"}`

**Where is state stored?**

* If `--state-disk` is provided (or defaults to the first upload disk), state is stored **on that disk**, under `--state-path` (default: `{diskBase}/_state`, or `_state` if base is empty).
* If no disks are used, state is stored **locally** at `storage/app/db-backups/_state`.

**Examples**

* Disk base `backups/prod` → `backups/prod/_state/{conn}_mysql_state.json`
* Disk base `""` (root) → `_state/{conn}_mysql_state.json`

**Date Range Examples**

```bash
# Export last month's data
php artisan db:backup \
  --connection=pgsql \
  --mode=incremental \
  --from-date="2025-10-01" \
  --to-date="2025-10-31 23:59:59" \
  --incremental-format=sql \
  --pg-csv-include=public.*

# Export today's changes only
php artisan db:backup \
  --connection=mysql \
  --mode=incremental \
  --incremental-type=updated_at \
  --from-date="2025-11-24 00:00:00" \
  --incremental-format=csv \
  --mysql-csv-include=orders,inventory
```

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

#### PostgreSQL CSV

Load into staging tables then **upsert** into targets using primary keys:

```sql
\copy staging_orders FROM 'public_orders.csv' CSV HEADER;
-- MERGE / UPSERT into public.orders using ON CONFLICT
INSERT INTO public.orders SELECT * FROM staging_orders
ON CONFLICT (id) DO UPDATE SET ...;
```

*Note:* CSV incremental does **not** capture deletes — schedule periodic full backups.

#### PostgreSQL SQL

Apply SQL files directly:

```bash
# Separate files
psql -h HOST -U USER -d DB_NAME -f public_orders.sql
psql -h HOST -U USER -d DB_NAME -f public_users.sql

# Combined file (gzipped)
gzcat app_db_pg_incremental_20251124.sql.gz | psql -h HOST -U USER -d DB_NAME

# Single file
psql -h HOST -U USER -d DB_NAME < app_db_pg_incremental_20251124.sql
```

*Note:* SQL format includes INSERT statements. Handle duplicate key conflicts based on your needs.

#### MySQL CSV

Load into staging tables then **upsert**:

```bash
# Load CSV into staging
mysql -h HOST -u USER -p DB_NAME -e "LOAD DATA LOCAL INFILE 'orders.csv' INTO TABLE staging_orders FIELDS TERMINATED BY '\t' LINES TERMINATED BY '\n'"

# Upsert into target
mysql -h HOST -u USER -p DB_NAME -e "INSERT INTO orders SELECT * FROM staging_orders ON DUPLICATE KEY UPDATE ..."
```

#### MySQL SQL (updated_at)

Apply SQL files directly:

```bash
# Separate files
mysql -h HOST -u USER -p DB_NAME < orders.sql
mysql -h HOST -u USER -p DB_NAME < users.sql

# Combined file (gzipped)
gzcat app_db_my_incremental_20251124.sql.gz | mysql -h HOST -u USER -p DB_NAME
```

#### MySQL binlog SQL

Apply directly:

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
composer test            # runs vendor/bin/phpunit --testdox
# or directly:
vendor/bin/phpunit --testdox
```

If you see version conflicts with Laravel/Testbench/PHPUnit, align versions (e.g., Testbench 10 for Laravel 12, Testbench 11 for Laravel 13).
A CI matrix tests PHP 8.2–8.5 × Laravel 10/11/12.

---

## Troubleshooting

* **“Required tool not found…”**
  Install the DB client tools your mode needs (`pg_dump`, `psql`, `pg_dumpall`, `mysqldump`, `mysqlbinlog` for MySQL binlog mode, `mysql` client for MySQL `updated_at` mode).

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
|   10.x  | ≥8.2 |    ^8.0   |  ^10.5  |
|   11.x  | ≥8.2 |    ^9.0   |  ^10.5  |
|   12.x  | ≥8.2 |   ^10.0   |  ^11.x  |
|   13.x  | ≥8.2 |   ^11.0   |  ^12.x  |

---

## Security Notes

* **Secrets on CLI:** `mysqldump` receives `--password=...` as a CLI argument which may be visible to OS process lists. Run backups on trusted hosts. Passwords are automatically redacted from error messages and logs by the built-in `SecretRedactor`. (PostgreSQL uses the `PGPASSWORD` environment variable for `pg_dump`/`psql`, keeping credentials out of the process list.)
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

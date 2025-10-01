<?php

return [

    // Default CLI tools (override via env or command options)
    'tools' => [
        'pg_dump'     => env('DBBACKUP_PG_DUMP', 'pg_dump'),
        'pg_dumpall'  => env('DBBACKUP_PG_DUMPALL', 'pg_dumpall'),
        'psql'        => env('DBBACKUP_PSQL', 'psql'),
        'mysqldump'   => env('DBBACKUP_MYSQLDUMP', 'mysqldump'),
        'mysqlbinlog' => env('DBBACKUP_MYSQLBINLOG', 'mysqlbinlog'),
    ],

    'upload' => [
        'disks'       => [],       // e.g. ['s3','wasabi']
        'remote_path' => '',       // fallback for disks not present in remote_map
        'remote_map'  => [         // e.g. ['s3' => 'backups/prod', 'wasabi' => 'backups/dr']
        ],
    ],

    'retention' => [
        'keep' => null,            // keep last N backup sets
        'days' => null,            // delete sets older than D days
    ],
];

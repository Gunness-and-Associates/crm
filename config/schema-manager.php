<?php

return [
    // Storage disk snapshots are written to (Storage facade — tenancy rule 5).
    'snapshot_disk' => env('SCHEMA_MANAGER_SNAPSHOT_DISK', 'local'),
    'snapshot_retention_days' => (int) env('SCHEMA_MANAGER_SNAPSHOT_RETENTION_DAYS', 30),

    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),

    // Named-lock timeout for concurrent DDL rejection (BACKEND_BRIEF §6.2 step 2).
    'lock_timeout_seconds' => (int) env('SCHEMA_MANAGER_LOCK_TIMEOUT', 10),

    // Default per-module custom-field ceiling (BACKEND_BRIEF §6.3).
    'max_custom_fields_per_module' => (int) env('SCHEMA_MANAGER_MAX_FIELDS', 150),
];

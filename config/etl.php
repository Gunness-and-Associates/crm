<?php

// Local-only ETL configuration (BACKEND_BRIEF §13 — "runs locally against a
// copy, never production"). LEGACY_PHP_ROOT points at the legacy SuiteCRM
// install's `public/legacy` directory (its `modules/` and `custom/modules/`
// subtrees hold the Studio-generated *viewdefs.php files crm:import-studio-
// metadata's layout import reads) — never present in CI or on any deployed
// environment, matching how LEGACY_DB_* already works for the legacy DB
// connection in config/database.php.
return [
    'legacy_php_root' => env('LEGACY_PHP_ROOT'),
];

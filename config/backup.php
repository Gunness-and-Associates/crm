<?php

return [
    // BACKEND_BRIEF's own open-question default: "nightly dump to object
    // storage" — the 's3' disk config/filesystems.php already ships. Falls
    // back to the schema-manager snapshot disk (local) when unset, so a
    // fresh local install works with no extra .env keys.
    'disk' => env('BACKUP_DISK'),

    // Object storage lifecycle rules (e.g. an S3 bucket policy) are the
    // real production retention mechanism for a nightly full dump -- not
    // application code, and not the same concern as SchemaManager's own
    // schema-change-snapshot retention (config/schema-manager.php's
    // snapshot_retention_days), which is driven by `changes` rows a backup
    // never creates.
];

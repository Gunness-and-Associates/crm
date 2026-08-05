<?php

namespace App\Support\SchemaManager;

/**
 * The mandatory pre-DDL snapshot could not be taken. apply() must abort before
 * executing any DDL (BACKEND_BRIEF §6.2 step 3).
 */
final class SnapshotFailed extends \RuntimeException {}

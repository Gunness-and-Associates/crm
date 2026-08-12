<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Z-3.3: keep the schema-change snapshot disk bounded (BACKEND_BRIEF §6 per-installation limit).
Schedule::command('schema:prune-snapshots')->daily();

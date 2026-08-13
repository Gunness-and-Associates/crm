<?php

use App\Jobs\SendDailyCountReportJob;
use App\Jobs\SendReminderNotificationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Z-3.3: keep the schema-change snapshot disk bounded (BACKEND_BRIEF §6 per-installation limit).
Schedule::command('schema:prune-snapshots')->daily();

// Z-4.2: scheduled jobs and notifications. Both run on the queue (Horizon in
// production, the database driver locally/CI) rather than inline on the scheduler.
Schedule::job(new SendDailyCountReportJob)->dailyAt('07:00');
Schedule::job(new SendReminderNotificationsJob)->dailyAt('08:00');

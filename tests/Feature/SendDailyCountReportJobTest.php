<?php

use App\Jobs\SendDailyCountReportJob;
use App\Mail\DailyCountReportMail;
use App\Models\Lead;
use App\Models\Student;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('is a no-op until report recipients are configured', function () {
    Mail::fake();

    app(SendDailyCountReportJob::class)->handle(app(Settings::class));

    Mail::assertNothingSent();
});

it('emails the configured recipients with today\'s lead and student counts', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.daily_report_recipients', ['ops@gunness.test']);

    Lead::factory()->create(['hot_lead' => true]);
    Lead::factory()->create(['stage' => 'lost']);
    Student::factory()->create();

    app(SendDailyCountReportJob::class)->handle(app(Settings::class));

    Mail::assertSent(DailyCountReportMail::class, function (DailyCountReportMail $mail) {
        return $mail->hasTo('ops@gunness.test')
            && $mail->counts['total_leads'] === 2
            && $mail->counts['hot_leads'] === 1
            && $mail->counts['open_leads'] === 1
            && $mail->counts['total_students'] === 1;
    });
});

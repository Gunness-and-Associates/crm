<?php

namespace App\Jobs;

use App\Enums\LeadStage;
use App\Mail\DailyCountReportMail;
use App\Models\Lead;
use App\Models\Student;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Daily lead and student count report (Z-4.2), mailed to the recipients an
 * administrator configured in the settings store (never .env — tenancy-ready
 * rule 3). A no-op until that list is set. Runs with no authenticated user,
 * so the ACL-scoped Lead model must bypass AppliesRecordAccess — this is a
 * company-wide digest, not scoped to any one owner.
 */
final class SendDailyCountReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(Settings $settings): void
    {
        $recipients = $settings->get('notifications.daily_report_recipients', []);
        if (! is_array($recipients) || $recipients === []) {
            return;
        }

        $today = Carbon::today();

        $counts = [
            'new_leads_today' => Lead::withoutGlobalScopes()->whereDate('created_at', $today)->count(),
            'total_leads' => Lead::withoutGlobalScopes()->count(),
            'open_leads' => Lead::withoutGlobalScopes()
                ->whereNotIn('stage', [LeadStage::Converted->value, LeadStage::Lost->value])
                ->count(),
            'hot_leads' => Lead::withoutGlobalScopes()->where('hot_lead', true)->count(),
            'new_students_today' => Student::withoutGlobalScopes()->whereDate('created_at', $today)->count(),
            'total_students' => Student::withoutGlobalScopes()->count(),
        ];

        Mail::to($recipients)->send(new DailyCountReportMail($counts, $today));
    }
}

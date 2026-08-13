<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\ReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Daily task and follow-up reminders (Z-4.2). Runs with no authenticated user,
 * so the ACL-scoped models (Lead, Client) must bypass AppliesRecordAccess —
 * this reads across every owner's records to notify each one, it is not
 * acting on behalf of a single signed-in user.
 */
final class SendReminderNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $today = Carbon::today();

        Task::query()
            ->whereNotNull('assigned_user_id')
            ->where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $today)
            ->with('assignedUser')
            ->each(function (Task $task): void {
                $task->assignedUser?->notify(new ReminderNotification(
                    reason: 'task_due',
                    subjectType: Task::class,
                    subjectId: $task->id,
                    label: $task->name,
                    dueAt: Carbon::parse($task->due_date),
                ));
            });

        Lead::withoutGlobalScopes()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now())
            ->with('assignedUser')
            ->each(function (Lead $lead): void {
                if ($lead->next_follow_up_at === null) {
                    return;
                }

                $lead->assignedUser?->notify(new ReminderNotification(
                    reason: 'follow_up_due',
                    subjectType: Lead::class,
                    subjectId: $lead->id,
                    label: $lead->fullName(),
                    dueAt: $lead->next_follow_up_at,
                ));
            });

        Client::withoutGlobalScopes()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->with('assignedUser')
            ->each(function (Client $client): void {
                if ($client->next_action_at === null) {
                    return;
                }

                $client->assignedUser?->notify(new ReminderNotification(
                    reason: 'follow_up_due',
                    subjectType: Client::class,
                    subjectId: $client->id,
                    label: $client->fullName(),
                    dueAt: $client->next_action_at,
                ));
            });
    }
}

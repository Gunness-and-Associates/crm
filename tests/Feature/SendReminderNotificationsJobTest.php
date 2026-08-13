<?php

use App\Jobs\SendReminderNotificationsJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Fixtures\ContactableFixture;

uses(RefreshDatabase::class);

it('notifies the assigned user of an overdue task, but not a completed one', function () {
    Notification::fake();

    $user = User::factory()->create();
    $overdue = Task::create([
        'subject_type' => ContactableFixture::class,
        'subject_id' => (string) Str::uuid(),
        'assigned_user_id' => $user->id,
        'name' => 'Send documents',
        'due_date' => now()->subDay(),
        'status' => 'not_started',
    ]);
    Task::create([
        'subject_type' => ContactableFixture::class,
        'subject_id' => (string) Str::uuid(),
        'assigned_user_id' => $user->id,
        'name' => 'Already done',
        'due_date' => now()->subDay(),
        'status' => 'completed',
    ]);

    (new SendReminderNotificationsJob)->handle();

    Notification::assertSentTo(
        $user,
        ReminderNotification::class,
        fn (ReminderNotification $n) => $n->subjectId === $overdue->id && $n->reason === 'task_due',
    );
    Notification::assertSentToTimes($user, ReminderNotification::class, 1);
});

it('notifies the assigned user of a lead whose follow-up is due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $lead = Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->addDay(),
    ]);

    (new SendReminderNotificationsJob)->handle();

    Notification::assertSentTo(
        $user,
        ReminderNotification::class,
        fn (ReminderNotification $n) => $n->subjectId === $lead->id && $n->reason === 'follow_up_due',
    );
    Notification::assertSentToTimes($user, ReminderNotification::class, 1);
});

it('notifies the assigned user of a client whose next action is due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $client = Client::factory()->create([
        'assigned_user_id' => $user->id,
        'next_action_at' => now()->subHour(),
    ]);

    (new SendReminderNotificationsJob)->handle();

    Notification::assertSentTo(
        $user,
        ReminderNotification::class,
        fn (ReminderNotification $n) => $n->subjectId === $client->id && $n->reason === 'follow_up_due',
    );
});

it('writes a real in-app notification row, not just the fake assertion', function () {
    $user = User::factory()->create();
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);

    (new SendReminderNotificationsJob)->handle();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->unreadNotifications()->count())->toBe(1);
});

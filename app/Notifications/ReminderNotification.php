<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * One in-app reminder shape shared by task due-dates and follow-up dates
 * (leads, clients) — Z-4.2. The frontend renders it generically from
 * subject_type/subject_id rather than one notification class per module.
 */
final class ReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reason,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $label,
        public readonly Carbon $dueAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reason' => $this->reason,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'label' => $this->label,
            'due_at' => $this->dueAt->toIso8601String(),
        ];
    }
}

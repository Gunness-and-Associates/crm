<?php

namespace App\Jobs;

use App\Mail\RecordEmail;
use App\Models\Email;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * BACKEND_BRIEF §10/§11: "Log every send with its result" — the Email activity
 * row created by EmailSender *is* that log; this job's only job is to actually
 * send and update its status. Runs on the `mail` queue, 3 tries.
 */
final class SendRecordEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $emailLogId)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $log = Email::query()->find($this->emailLogId);
        if ($log === null) {
            return;
        }

        $to = $log->to_addresses;
        if ($to === []) {
            $log->update(['status' => 'failed']);

            return;
        }

        Mail::to($to)
            ->cc($log->cc_addresses ?? [])
            ->send(new RecordEmail((string) $log->subject_line, (string) $log->body_html, $log->from_address));

        $log->update(['status' => 'sent', 'sent_at' => now()]);
    }

    public function failed(\Throwable $exception): void
    {
        Email::query()->where('id', $this->emailLogId)->update(['status' => 'failed']);

        Log::channel('api')->error('record_email_send_failed', [
            'email_log_id' => $this->emailLogId,
            'error' => $exception->getMessage(),
        ]);
    }
}

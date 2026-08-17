<?php

namespace App\Support\Email;

use App\Jobs\SendRecordEmailJob;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "Send-from-record" (Z-5.8 / S-5.4's compose screen calls into this directly —
 * it's an in-app Filament feature, not a REST endpoint). Every send creates an
 * Email activity row (the delivery log) up front with status=draft, then queues
 * the actual delivery — so a send is visible on the record's timeline
 * immediately, and its status flips to sent/failed once SendRecordEmailJob runs.
 */
final class EmailSender
{
    public function __construct(private readonly MergeFieldResolver $resolver) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function send(
        Model $record,
        string $moduleKey,
        array $to,
        string $subject,
        string $bodyHtml,
        ?string $fromAddress = null,
        array $cc = [],
        ?User $actor = null,
    ): Email {
        $configuredFrom = config('mail.from.address');
        $log = Email::query()->create([
            'subject_type' => $record::class,
            'subject_id' => $record->getKey(),
            'subject_line' => $this->resolver->resolve($subject, $record, $moduleKey),
            'from_address' => $fromAddress ?? (is_string($configuredFrom) ? $configuredFrom : ''),
            'to_addresses' => $to,
            'cc_addresses' => $cc === [] ? null : $cc,
            'body_html' => $this->resolver->resolve($bodyHtml, $record, $moduleKey),
            'status' => 'draft',
            'assigned_user_id' => $actor?->id,
            'created_by' => $actor?->id,
        ]);

        SendRecordEmailJob::dispatch($log->id);

        return $log;
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function sendFromTemplate(
        EmailTemplate $template,
        Model $record,
        string $moduleKey,
        array $to,
        array $cc = [],
        ?User $actor = null,
    ): Email {
        return $this->send($record, $moduleKey, $to, $template->subject, $template->body_html, null, $cc, $actor);
    }
}

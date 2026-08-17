<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Built and sent from inside SendRecordEmailJob, which already runs on the
 * queue — this is never itself dispatched. Subject/body arrive pre-resolved
 * (merge fields already substituted by MergeFieldResolver).
 */
final class RecordEmail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $resolvedSubject,
        public readonly string $resolvedBodyHtml,
        public readonly ?string $fromAddress = null,
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->resolvedSubject)->html($this->resolvedBodyHtml);

        return $this->fromAddress !== null ? $mail->from($this->fromAddress) : $mail;
    }
}

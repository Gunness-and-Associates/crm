<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Not itself ShouldQueue — it is always built and sent from inside
 * SendDailyCountReportJob, which already runs on the queue.
 */
final class DailyCountReportMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly array $counts,
        public readonly Carbon $date,
    ) {}

    public function build(): self
    {
        return $this->subject('Daily lead & student report — '.$this->date->toDateString())
            ->view('emails.daily-count-report');
    }
}

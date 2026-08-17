<?php

use App\Jobs\SendRecordEmailJob;
use App\Mail\RecordEmail;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Support\Email\EmailSender;
use App\Support\Email\MergeFieldResolver;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);
});

it('resolves a known field placeholder from the record\'s current value', function () {
    $lead = Lead::factory()->create(['source' => 'referral']);

    $resolved = app(MergeFieldResolver::class)->resolve('Source: {{source}}', $lead, 'leads');

    expect($resolved)->toBe('Source: referral');
});

it('resolves full_name via the Contactable helper, not getAttribute', function () {
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    $resolved = app(MergeFieldResolver::class)->resolve('Hi {{full_name}}', $lead, 'leads');

    expect($resolved)->toBe('Hi Amina Khan');
});

it('leaves an unknown placeholder as-is rather than blanking it', function () {
    $lead = Lead::factory()->create();

    $resolved = app(MergeFieldResolver::class)->resolve('{{not_a_real_field}}', $lead, 'leads');

    expect($resolved)->toBe('{{not_a_real_field}}');
});

it('sends an email from a record, logging it as an Email activity with resolved merge fields', function () {
    Mail::fake();
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);
    $actor = User::factory()->create();

    $log = app(EmailSender::class)->send(
        record: $lead,
        moduleKey: 'leads',
        to: ['amina@example.com'],
        subject: 'Hello {{full_name}}',
        bodyHtml: '<p>Hi {{full_name}}, welcome.</p>',
        actor: $actor,
    );

    expect($log)->toBeInstanceOf(Email::class)
        ->and($log->subject_type)->toBe(Lead::class)
        ->and($log->subject_id)->toBe($lead->id)
        ->and($log->subject_line)->toBe('Hello Amina Khan')
        ->and($log->body_html)->toBe('<p>Hi Amina Khan, welcome.</p>')
        ->and($log->to_addresses)->toBe(['amina@example.com'])
        ->and($log->created_by)->toBe($actor->id)
        // QUEUE_CONNECTION=sync in testing — SendRecordEmailJob already ran.
        ->and($log->fresh()->status)->toBe('sent')
        ->and($log->fresh()->sent_at)->not->toBeNull();

    Mail::assertSent(RecordEmail::class, fn (RecordEmail $mail): bool => $mail->resolvedSubject === 'Hello Amina Khan');
});

it('sends from a stored template', function () {
    Mail::fake();
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);
    $template = EmailTemplate::factory()->create([
        'subject' => 'Welcome, {{full_name}}',
        'body_html' => '<p>{{full_name}}, thanks.</p>',
    ]);

    $log = app(EmailSender::class)->sendFromTemplate($template, $lead, 'leads', ['amina@example.com']);

    expect($log->subject_line)->toBe('Welcome, Amina Khan');
    Mail::assertSent(RecordEmail::class);
});

it('marks the log failed without dispatching a job when there are no recipients', function () {
    Mail::fake();
    $lead = Lead::factory()->create();

    $log = app(EmailSender::class)->send($lead, 'leads', [], 'Subject', '<p>Body</p>');

    expect($log->fresh()->status)->toBe('failed');
    Mail::assertNothingSent();
});

it('marks the log failed once the job\'s retries are exhausted', function () {
    $lead = Lead::factory()->create();
    $log = Email::query()->create([
        'subject_type' => Lead::class,
        'subject_id' => $lead->id,
        'from_address' => 'a@x.com',
        'to_addresses' => ['b@x.com'],
        'body_html' => '<p>Body</p>',
        'status' => 'draft',
    ]);

    (new SendRecordEmailJob($log->id))->failed(new RuntimeException('SMTP down'));

    expect($log->fresh()->status)->toBe('failed');
});

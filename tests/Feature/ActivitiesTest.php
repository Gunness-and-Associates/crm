<?php

use App\Models\Call;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Email;
use App\Models\Meeting;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ContactableFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('contactable_fixtures')) {
        Schema::create('contactable_fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->contactable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
});

it('morphs every activity type to its subject', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);

    $meeting = Meeting::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'Intro call', 'date_start' => now()]);
    $note = Note::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'body' => 'Called back']);
    $document = Document::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'Passport.pdf', 'file_path' => 'docs/passport.pdf']);
    $call = Call::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'direction' => 'outbound', 'date_start' => now()]);
    $task = Task::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'Follow up']);
    $email = Email::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'from_address' => 'a@x.com', 'to_addresses' => ['b@x.com']]);

    foreach ([$meeting, $note, $document, $call, $task, $email] as $activity) {
        expect($activity->subject->is($record))->toBeTrue();
    }
});

it('lists activities from the record side via HasActivities', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);
    Meeting::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'Intro', 'date_start' => now()]);
    Task::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'Follow up']);

    expect($record->meetings)->toHaveCount(1)
        ->and($record->tasks)->toHaveCount(1)
        ->and($record->notes)->toHaveCount(0);
});

it('builds a combined, newest-first activity feed', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);
    $older = Note::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'body' => 'first']);
    $older->forceFill(['created_at' => now()->subDay()])->saveQuietly();
    Task::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'second']);

    $feed = $record->activityFeed();

    expect($feed)->toHaveCount(2)
        ->and($feed->first())->toBeInstanceOf(Task::class);
});

it('keeps document revisions linked to their document', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);
    $document = Document::create(['subject_type' => ContactableFixture::class, 'subject_id' => $record->id, 'name' => 'v1.pdf', 'file_path' => 'docs/v1.pdf']);
    DocumentRevision::create(['document_id' => $document->id, 'revision_number' => 1, 'file_path' => 'docs/v1.pdf']);
    DocumentRevision::create(['document_id' => $document->id, 'revision_number' => 2, 'file_path' => 'docs/v2.pdf']);

    expect($document->revisions)->toHaveCount(2)
        ->and($document->revisions->first()->revision_number)->toBe(2);
});

it('does not ship telephony fields on the call log', function () {
    expect(Schema::hasColumn('calls', 'asterisk_extension'))->toBeFalse()
        ->and(Schema::hasColumn('calls', 'ami_secret'))->toBeFalse();
});

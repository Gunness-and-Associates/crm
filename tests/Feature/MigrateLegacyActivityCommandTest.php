<?php

use App\Models\Call;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Note;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTruncation::class);

/**
 * Notes/Calls/Meetings/Documents (Z-6.2 activities, part 1 of the load
 * order's "activities" step) — resolved via legacy per-module junction
 * tables (the real SuiteCRM linkage mechanism for GA_* modules) or, for
 * Notes only, a direct parent_type/parent_id on the activity table itself.
 * Anything that doesn't resolve to an entity we've migrated is skipped, not
 * guessed — most real Notes/Calls/Meetings point at the dead stock
 * `Accounts` module and are correctly left out.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('notes', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('filename')->nullable();
        $table->string('parent_type')->nullable();
        $table->string('parent_id', 36)->nullable();
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
    });

    Schema::connection('legacy')->create('ga_companies_notes_c', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('ga_companies_notesga_companies_ida', 36)->nullable();
        $table->string('ga_companies_notesnotes_idb', 36)->nullable();
    });
    // NoteTransformer's other real junction/parent_type specs — empty in this
    // fixture, but must exist for resolveActivitySubjects() to query them.
    foreach ([
        'ga_lmia_course_notes_c' => 'ga_lmia_course',
        'ga_study_notes_c' => 'ga_study',
        'ga_assessment_score_notes_c' => 'ga_assessment_score',
        'ga_galead_notes_c' => 'ga_galead',
        'ga_imm_can_notes_c' => 'ga_imm_can',
        'ga_usa_notes_c' => 'ga_usa',
        'ga_imm_biz_notes_c' => 'ga_imm_biz',
        'ga_bd1_notes_c' => 'ga_bd1',
        'ga_clientdevelopment2_notes_c' => 'ga_clientdevelopment2',
        'ga_applicant_notes_c' => 'ga_applicant',
    ] as $table => $module) {
        Schema::connection('legacy')->create($table, function (Blueprint $blueprint) use ($table, $module) {
            $base = substr($table, 0, -2); // strip trailing "_c"
            $blueprint->string('id', 36)->primary();
            $blueprint->boolean('deleted')->default(false);
            $blueprint->string('date_modified')->nullable();
            $blueprint->string("{$base}{$module}_ida", 36)->nullable();
            $blueprint->string("{$base}notes_idb", 36)->nullable();
        });
    }

    Schema::connection('legacy')->create('calls', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('description')->nullable();
        $table->string('direction')->nullable();
        $table->string('date_start')->nullable();
        $table->integer('duration_hours')->nullable();
        $table->integer('duration_minutes')->nullable();
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
    });
    Schema::connection('legacy')->create('ga_companies_calls_c', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('ga_companies_callsga_companies_ida', 36)->nullable();
        $table->string('ga_companies_callscalls_idb', 36)->nullable();
    });
    Schema::connection('legacy')->create('ga_lmia_course_calls_c', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('ga_lmia_course_callsga_lmia_course_ida', 36)->nullable();
        $table->string('ga_lmia_course_callscalls_idb', 36)->nullable();
    });

    Schema::connection('legacy')->create('meetings', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('name')->nullable();
        $table->string('location')->nullable();
        $table->string('date_start')->nullable();
        $table->string('date_end')->nullable();
        $table->integer('duration_hours')->nullable();
        $table->integer('duration_minutes')->nullable();
        $table->string('status')->nullable();
        $table->string('description')->nullable();
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
    });
    Schema::connection('legacy')->create('ga_lmia_course_meetings_c', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('ga_lmia_course_meetingsga_lmia_course_ida', 36)->nullable();
        $table->string('ga_lmia_course_meetingsmeetings_idb', 36)->nullable();
    });

    Schema::connection('legacy')->create('documents', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('document_name')->nullable();
        $table->string('doc_url')->nullable();
        $table->string('category_id')->nullable();
        $table->string('status_id')->nullable();
        $table->boolean('is_template')->default(false);
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
    });
    Schema::connection('legacy')->create('ga_usa_documents_1_c', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('ga_usa_documents_1ga_usa_ida', 36)->nullable();
        $table->string('ga_usa_documents_1documents_idb', 36)->nullable();
    });

    Schema::connection('legacy')->create('document_revisions', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('document_id', 36)->nullable();
        $table->string('revision')->nullable();
        $table->string('file_mime_type')->nullable();
        $table->string('doc_url')->nullable();
        $table->string('created_by', 36)->nullable();
    });
});

it('migrates a note linked via a per-module junction table onto its resolved subject', function () {
    $company = Company::factory()->create();
    DB::connection('legacy')->table('notes')->insert([
        'id' => 'note-1', 'name' => 'Follow up', 'description' => 'Called about pricing',
    ]);
    DB::connection('legacy')->table('ga_companies_notes_c')->insert([
        'id' => 'rel-1', 'ga_companies_notesga_companies_ida' => $company->id, 'ga_companies_notesnotes_idb' => 'note-1',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_notes'])->assertExitCode(0);

    $note = Note::withoutGlobalScopes()->find('note-1');
    expect($note)->not->toBeNull()
        ->and($note->subject_type)->toBe(Company::class)
        ->and($note->subject_id)->toBe($company->id)
        ->and($note->body)->toBe('Called about pricing');
});

it('skips a note whose parent_type does not resolve to a migrated entity', function () {
    DB::connection('legacy')->table('notes')->insert([
        'id' => 'note-2', 'parent_type' => 'Accounts', 'parent_id' => 'dead-account-id',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_notes'])->assertExitCode(0);

    expect(Note::withoutGlobalScopes()->find('note-2'))->toBeNull();
});

it('skips a note whose parent_type resolves to a real module but whose parent_id matches nothing there', function () {
    // Note has no DB-level FK on subject_id (it is polymorphic) -- an
    // unresolvable parent_id must be caught by an explicit existence check
    // against the target table, not left to error (or worse, silently
    // become a dangling reference).
    DB::connection('legacy')->table('notes')->insert([
        'id' => 'note-4', 'parent_type' => 'GA_Applicant', 'parent_id' => 'no-such-lead-id',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_notes'])->assertExitCode(0);

    expect(Note::withoutGlobalScopes()->find('note-4'))->toBeNull();
});

it('normalises call direction to lowercase and computes duration_minutes from hours+minutes', function () {
    $company = Company::factory()->create();
    DB::connection('legacy')->table('calls')->insert([
        'id' => 'call-1', 'direction' => 'Inbound', 'duration_hours' => 1, 'duration_minutes' => 15,
        'date_start' => '2026-01-15 10:00:00',
    ]);
    DB::connection('legacy')->table('ga_companies_calls_c')->insert([
        'id' => 'rel-2', 'ga_companies_callsga_companies_ida' => $company->id, 'ga_companies_callscalls_idb' => 'call-1',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_calls'])->assertExitCode(0);

    $call = Call::withoutGlobalScopes()->find('call-1');
    expect($call->direction)->toBe('inbound')
        ->and($call->duration_minutes)->toBe(75);
});

it('normalises meeting status to lowercase', function () {
    $lead = Lead::factory()->create();
    DB::connection('legacy')->table('meetings')->insert([
        'id' => 'meeting-1', 'status' => 'Held', 'date_start' => '2026-01-15 10:00:00',
    ]);
    DB::connection('legacy')->table('ga_lmia_course_meetings_c')->insert([
        'id' => 'rel-3', 'ga_lmia_course_meetingsga_lmia_course_ida' => $lead->id, 'ga_lmia_course_meetingsmeetings_idb' => 'meeting-1',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_meetings'])->assertExitCode(0);

    expect(Meeting::withoutGlobalScopes()->find('meeting-1')->status)->toBe('held');
});

it('falls back to a legacy:// placeholder file_path when no doc_url exists, and migrates its revision', function () {
    $lead = Lead::factory()->create();
    DB::connection('legacy')->table('documents')->insert(['id' => 'doc-1', 'document_name' => 'Passport scan']);
    DB::connection('legacy')->table('ga_usa_documents_1_c')->insert([
        'id' => 'rel-4', 'ga_usa_documents_1ga_usa_ida' => $lead->id, 'ga_usa_documents_1documents_idb' => 'doc-1',
    ]);
    DB::connection('legacy')->table('document_revisions')->insert([
        'id' => 'rev-1', 'document_id' => 'doc-1', 'revision' => '2',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_documents'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_document_revisions'])->assertExitCode(0);

    $document = Document::withoutGlobalScopes()->find('doc-1');
    expect($document->name)->toBe('Passport scan')
        ->and($document->file_path)->toBe('legacy://documents/doc-1')
        ->and(DocumentRevision::find('rev-1')->revision_number)->toBe(2);
});

it('re-runs idempotently without duplicating rows', function () {
    $company = Company::factory()->create();
    DB::connection('legacy')->table('notes')->insert(['id' => 'note-3']);
    DB::connection('legacy')->table('ga_companies_notes_c')->insert([
        'id' => 'rel-5', 'ga_companies_notesga_companies_ida' => $company->id, 'ga_companies_notesnotes_idb' => 'note-3',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_notes'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_notes'])->assertExitCode(0);

    expect(Note::withoutGlobalScopes()->count())->toBe(1);
});

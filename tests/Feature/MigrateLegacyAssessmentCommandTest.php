<?php

use App\Models\Assessment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Assessment is not Contactable — sourced from two independent tables,
 * ga_assessment_request and ga_assessment_score (Z-6.2 part 3).
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('ga_assessment_request', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('marital_status')->nullable();
        $table->string('highest_level_education')->nullable();
        $table->string('status')->nullable();
        $table->string('source')->nullable();
        $table->string('assigned_user_id', 36)->nullable();
    });

    Schema::connection('legacy')->create('ga_assessment_score', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('crs_score')->nullable();
        $table->string('fsw_score')->nullable();
        $table->string('education')->nullable();
        $table->string('speaking')->nullable();
        $table->string('field_of_study')->nullable();
        $table->string('assigned_user_id', 36)->nullable();
    });

    Schema::connection('legacy')->create('email_addresses', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_address')->nullable();
        $table->boolean('deleted')->default(false);
    });
    Schema::connection('legacy')->create('email_addr_bean_rel', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_address_id', 36);
        $table->string('bean_id', 36);
        $table->string('bean_module')->nullable();
        $table->boolean('primary_address')->default(false);
        $table->boolean('deleted')->default(false);
    });
});

it('migrates a request row, leaving status/case_type at their defaults and keeping the raw status in scores', function () {
    DB::connection('legacy')->table('ga_assessment_request')->insert([
        'id' => 'req-1', 'first_name' => 'Amina', 'marital_status' => 'Single',
        'highest_level_education' => 'Bachelor', 'status' => 'New', 'source' => 'Web',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'assessments_request'])->assertExitCode(0);

    $assessment = Assessment::withoutGlobalScopes()->find('req-1');
    expect($assessment)->not->toBeNull()
        ->and($assessment->status)->toBe('requested')
        ->and($assessment->case_type)->toBe('crs')
        ->and($assessment->marital_status)->toBe('Single')
        ->and($assessment->education_level)->toBe('Bachelor')
        ->and($assessment->scores)->toEqual(['source' => 'Web', 'legacy_status' => 'New']);
});

it('derives case_type from which score is present on a score row', function () {
    DB::connection('legacy')->table('ga_assessment_score')->insert([
        'id' => 'score-1', 'crs_score' => '470', 'education' => 'Masters', 'speaking' => '9',
    ]);
    DB::connection('legacy')->table('ga_assessment_score')->insert([
        'id' => 'score-2', 'fsw_score' => '60',
    ]);
    DB::connection('legacy')->table('ga_assessment_score')->insert([
        'id' => 'score-3', 'crs_score' => '480', 'fsw_score' => '65',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'assessments_score'])->assertExitCode(0);

    expect(Assessment::withoutGlobalScopes()->find('score-1')->case_type)->toBe('crs')
        ->and(Assessment::withoutGlobalScopes()->find('score-1')->crs_score)->toBe(470)
        ->and(Assessment::withoutGlobalScopes()->find('score-1')->education_level)->toBe('Masters')
        ->and(Assessment::withoutGlobalScopes()->find('score-1')->clb_speaking)->toBe(9)
        ->and(Assessment::withoutGlobalScopes()->find('score-2')->case_type)->toBe('fsw')
        ->and(Assessment::withoutGlobalScopes()->find('score-3')->case_type)->toBe('combined');
});

it('keeps unmapped scoring fields in the scores json blob', function () {
    DB::connection('legacy')->table('ga_assessment_score')->insert(['id' => 'score-4', 'field_of_study' => 'Engineering']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'assessments_score'])->assertExitCode(0);

    expect(Assessment::withoutGlobalScopes()->find('score-4')->scores)->toEqual(['field_of_study' => 'Engineering']);
});

it('re-runs idempotently without duplicating rows across either source', function () {
    DB::connection('legacy')->table('ga_assessment_request')->insert(['id' => 'req-2']);
    DB::connection('legacy')->table('ga_assessment_score')->insert(['id' => 'score-5']);

    foreach (['assessments_request', 'assessments_score'] as $key) {
        $this->artisan('crm:migrate-legacy', ['--only' => $key])->assertExitCode(0);
        $this->artisan('crm:migrate-legacy', ['--only' => $key])->assertExitCode(0);
    }

    expect(Assessment::withoutGlobalScopes()->count())->toBe(2);
});

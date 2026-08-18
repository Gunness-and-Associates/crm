<?php

use App\Models\Lead;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The remaining ~10 smaller/untargeted legacy lead modules (Z-6.2 part 2),
 * same self-contained sqlite-fixture approach as MigrateLegacyLeadCommandTest.
 */
beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);

    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('ga_resumes', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('document_name')->nullable();
        $table->string('filename')->nullable();
        $table->string('category')->nullable();
        $table->string('status_id')->nullable();
    });

    Schema::connection('legacy')->create('ga_new_pnp_form', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('name')->nullable();
        $table->string('lbl_phone')->nullable();
        $table->string('lbl_email')->nullable();
    });

    Schema::connection('legacy')->create('ga_canadavisa', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
    });
    Schema::connection('legacy')->create('ga_canadavisa_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->string('status_c')->nullable();
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

it('migrates ga_resumes, a module with no person-shaped columns at all, onto the fixed Resume vertical', function () {
    DB::connection('legacy')->table('ga_resumes')->insert([
        'id' => 'lead-resume-1', 'document_name' => 'CV - Jane Doe', 'filename' => 'cv.pdf',
        'category' => 'IT', 'status_id' => '3',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_resumes'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-resume-1');
    expect($lead)->not->toBeNull()
        ->and($lead->vertical->value)->toBe('Resume')
        ->and($lead->stage->value)->toBe('new')
        ->and($lead->first_name)->toBeNull()
        ->and($lead->vertical_attributes)->toEqual([
            'document_name' => 'CV - Jane Doe',
            'filename' => 'cv.pdf',
            'category' => 'IT',
            'status_id' => '3',
        ]);
});

it('keeps the raw webform lbl_* fields for ga_new_pnp_form in vertical_attributes', function () {
    DB::connection('legacy')->table('ga_new_pnp_form')->insert([
        'id' => 'lead-pnp-1', 'first_name' => 'Sam', 'name' => 'Sam Lee',
        'lbl_phone' => '555-1234', 'lbl_email' => 'sam@example.com',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_new_pnp_form'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-pnp-1');
    expect($lead->vertical->value)->toBe('PNP')
        ->and($lead->vertical_attributes)->toEqual([
            'name' => 'Sam Lee',
            'lbl_phone' => '555-1234',
            'lbl_email' => 'sam@example.com',
        ]);
});

it('canonicalises ga_canadavisa stage from the cstm status_c column', function () {
    DB::connection('legacy')->table('ga_canadavisa')->insert(['id' => 'lead-cv-1']);
    DB::connection('legacy')->table('ga_canadavisa_cstm')->insert(['id_c' => 'lead-cv-1', 'status_c' => 'Converted']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_canadavisa'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-cv-1');
    expect($lead->vertical->value)->toBe('CanadaVisa')
        ->and($lead->stage->value)->toBe('converted');
});

it('re-runs idempotently without duplicating the row', function () {
    DB::connection('legacy')->table('ga_resumes')->insert(['id' => 'lead-resume-2']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_resumes'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_resumes'])->assertExitCode(0);

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);
});

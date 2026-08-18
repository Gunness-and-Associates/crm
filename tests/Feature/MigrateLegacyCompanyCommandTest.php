<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Same self-contained sqlite-fixture approach as MigrateLegacyCommandTest —
 * the `legacy` connection never points at the real database in tests.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('ga_companies', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('salutation')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('title')->nullable();
        $table->string('department')->nullable();
        $table->text('description')->nullable();
        $table->boolean('do_not_call')->default(false);
        $table->string('phone_home')->nullable();
        $table->string('phone_mobile')->nullable();
        $table->string('phone_work')->nullable();
        $table->string('phone_other')->nullable();
        $table->string('phone_fax')->nullable();
        $table->string('primary_address_street')->nullable();
        $table->string('primary_address_city')->nullable();
        $table->string('primary_address_state')->nullable();
        $table->string('primary_address_postalcode')->nullable();
        $table->string('primary_address_country')->nullable();
        $table->string('alt_address_street')->nullable();
        $table->string('alt_address_city')->nullable();
        $table->string('alt_address_state')->nullable();
        $table->string('alt_address_postalcode')->nullable();
        $table->string('alt_address_country')->nullable();
        $table->string('lawful_basis')->nullable();
        $table->string('date_reviewed')->nullable();
        $table->string('lawful_basis_source')->nullable();
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
        $table->string('modified_user_id', 36)->nullable();
        $table->string('rating')->nullable();
        $table->string('lmia')->nullable();
        $table->string('jobpostlink')->nullable();
        $table->string('jobtitle')->nullable();
        $table->string('employees')->nullable();
        $table->string('company_type')->nullable();
        $table->string('company_contact_status')->nullable();
        $table->string('industry')->nullable();
        $table->string('website')->nullable();
        $table->string('contact_person_name')->nullable();
        $table->string('contactpersonphone')->nullable();
    });

    Schema::connection('legacy')->create('ga_companies_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->string('status_c')->nullable();
        $table->string('email1_c')->nullable();
        $table->string('pnp_program_c')->nullable();
        $table->string('resume_submitted_c')->nullable();
        $table->boolean('hot_lead_c')->default(false);
        $table->boolean('warm_lead_c')->default(false);
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

function insertLegacyCompany(array $overrides = []): void
{
    DB::connection('legacy')->table('ga_companies')->insert(array_merge([
        'id' => 'legacy-company-1',
        'deleted' => false,
        'date_modified' => '2026-01-01 00:00:00',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'company_contact_status' => null,
        'assigned_user_id' => null,
        'created_by' => null,
        'modified_user_id' => null,
        'rating' => null,
        'contactpersonphone' => null,
    ], $overrides));
}

it('migrates a legacy company, preserving id and recovering email via email_addr_bean_rel', function () {
    insertLegacyCompany();
    DB::connection('legacy')->table('ga_companies_cstm')->insert([
        'id_c' => 'legacy-company-1', 'status_c' => 'Prospect',
    ]);
    DB::connection('legacy')->table('email_addresses')->insert([
        'id' => 'email-1', 'email_address' => 'company@example.com', 'deleted' => false,
    ]);
    DB::connection('legacy')->table('email_addr_bean_rel')->insert([
        'id' => 'rel-1', 'email_address_id' => 'email-1', 'bean_id' => 'legacy-company-1',
        'bean_module' => 'GA_Companies', 'primary_address' => true, 'deleted' => false,
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    $company = Company::withoutGlobalScopes()->find('legacy-company-1');
    expect($company)->not->toBeNull()
        ->and($company->first_name)->toBe('Jane')
        ->and($company->last_name)->toBe('Doe')
        ->and($company->primary_email)->toBe('company@example.com');
});

it('falls back to email1_c when no email_addr_bean_rel row exists', function () {
    insertLegacyCompany();
    DB::connection('legacy')->table('ga_companies_cstm')->insert([
        'id_c' => 'legacy-company-1', 'email1_c' => 'cstm@example.com',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    expect(Company::withoutGlobalScopes()->find('legacy-company-1')->primary_email)->toBe('cstm@example.com');
});

it('prefers company_contact_status over the cstm status_c fallback', function () {
    insertLegacyCompany(['company_contact_status' => 'Active Client']);
    DB::connection('legacy')->table('ga_companies_cstm')->insert([
        'id_c' => 'legacy-company-1', 'status_c' => 'Prospect',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    expect(Company::withoutGlobalScopes()->find('legacy-company-1')->company_contact_status)->toBe('Active Client');
});

it('falls back to status_c when company_contact_status is empty', function () {
    insertLegacyCompany(['company_contact_status' => null]);
    DB::connection('legacy')->table('ga_companies_cstm')->insert([
        'id_c' => 'legacy-company-1', 'status_c' => 'Prospect',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    expect(Company::withoutGlobalScopes()->find('legacy-company-1')->company_contact_status)->toBe('Prospect');
});

it('coerces the varchar pnp_program_c/resume_submitted_c fields to booleans', function () {
    insertLegacyCompany();
    DB::connection('legacy')->table('ga_companies_cstm')->insert([
        'id_c' => 'legacy-company-1', 'pnp_program_c' => '1', 'resume_submitted_c' => '',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    $company = Company::withoutGlobalScopes()->find('legacy-company-1');
    expect($company->pnp_program)->toBeTrue()
        ->and($company->resume_submitted)->toBeFalse();
});

it('coerces a literal "0" varchar to false', function () {
    insertLegacyCompany();
    DB::connection('legacy')->table('ga_companies_cstm')->insert([
        'id_c' => 'legacy-company-1', 'pnp_program_c' => '0',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    expect(Company::withoutGlobalScopes()->find('legacy-company-1')->pnp_program)->toBeFalse();
});

it('sets deleted_at from date_modified when deleted=1', function () {
    insertLegacyCompany(['deleted' => true, 'date_modified' => '2025-06-15 10:30:00']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    $company = Company::withoutGlobalScopes()->find('legacy-company-1');
    expect($company->deleted_at)->not->toBeNull()
        ->and($company->deleted_at->format('Y-m-d H:i:s'))->toBe('2025-06-15 10:30:00');
});

it('nulls out a created_by/assigned_user_id/modified_by that is not UUID-shaped', function () {
    insertLegacyCompany([
        'assigned_user_id' => 'Prince Saha',
        'created_by' => 'jonathan dias',
        'modified_user_id' => 'carla@immigrationmatters.info',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    $company = Company::withoutGlobalScopes()->find('legacy-company-1');
    expect($company)->not->toBeNull()
        ->and($company->assigned_user_id)->toBeNull()
        ->and($company->created_by)->toBeNull()
        ->and($company->modified_by)->toBeNull();
});

it('keeps a genuinely UUID-shaped created_by that resolves to a real user', function () {
    $user = User::factory()->create();
    insertLegacyCompany(['created_by' => $user->id]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    expect(Company::withoutGlobalScopes()->find('legacy-company-1')->created_by)->toBe($user->id);
});

it('re-runs idempotently without duplicating the row', function () {
    insertLegacyCompany();

    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'companies'])->assertExitCode(0);

    expect(Company::withoutGlobalScopes()->count())->toBe(1);
});

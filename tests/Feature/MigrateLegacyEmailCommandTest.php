<?php

use App\Models\Company;
use App\Models\Email;
use App\Models\Lead;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Email (Z-6.2 activities, the biggest/last activity type) — parent_type on
 * `emails` carries the real linkage signal (unlike Notes/Calls/Meetings,
 * where it is mostly the dead stock Accounts module) but mixes current
 * module names with historical pre-rename aliases (hamid_*, SZ_*). Every
 * alias is empirically verified via the same existence check
 * ResolvesActivitySubjects already applies, not a guess.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('emails', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('name')->nullable();
        $table->string('status')->nullable();
        $table->string('date_sent_received')->nullable();
        $table->string('parent_type')->nullable();
        $table->string('parent_id', 36)->nullable();
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
    });

    Schema::connection('legacy')->create('emails_text', function (Blueprint $table) {
        $table->string('email_id', 36)->primary();
        $table->string('from_addr')->nullable();
        $table->text('description')->nullable();
        $table->text('description_html')->nullable();
    });

    Schema::connection('legacy')->create('email_addresses', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_address')->nullable();
        $table->boolean('deleted')->default(false);
    });

    Schema::connection('legacy')->create('emails_email_addr_rel', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_id', 36)->nullable();
        $table->string('address_type')->nullable();
        $table->string('email_address_id', 36)->nullable();
        $table->boolean('deleted')->default(false);
    });

    Schema::connection('legacy')->create('ga_lmia_course_emails_c', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('ga_lmia_course_emailsga_lmia_course_ida', 36)->nullable();
        $table->string('ga_lmia_course_emailsemails_idb', 36)->nullable();
    });
    // EmailTransformer's other real junction specs -- empty in this fixture,
    // but must exist for resolveActivitySubjects() to query them.
    foreach ([
        'ga_study_emails_c' => 'ga_study',
        'ga_imm_can_emails_c' => 'ga_imm_can',
        'ga_usa_emails_c' => 'ga_usa',
        'ga_lmiainquiry_emails_c' => 'ga_lmiainquiry',
    ] as $table => $module) {
        Schema::connection('legacy')->create($table, function (Blueprint $blueprint) use ($table, $module) {
            $base = substr($table, 0, -2);
            $blueprint->string('id', 36)->primary();
            $blueprint->boolean('deleted')->default(false);
            $blueprint->string('date_modified')->nullable();
            $blueprint->string("{$base}{$module}_ida", 36)->nullable();
            $blueprint->string("{$base}emails_idb", 36)->nullable();
        });
    }
});

function insertLegacyEmail(string $id, array $overrides = []): void
{
    DB::connection('legacy')->table('emails')->insert(array_merge(['id' => $id], $overrides));
}

function attachEmailAddress(string $emailId, string $addressType, string $address): void
{
    $addressId = "addr-{$emailId}-{$addressType}";
    DB::connection('legacy')->table('email_addresses')->insert(['id' => $addressId, 'email_address' => $address]);
    DB::connection('legacy')->table('emails_email_addr_rel')->insert([
        'id' => "rel-{$emailId}-{$addressType}", 'email_id' => $emailId, 'address_type' => $addressType, 'email_address_id' => $addressId,
    ]);
}

it('resolves via a real per-module junction table and collects from/to/cc addresses', function () {
    $lead = Lead::factory()->create();
    insertLegacyEmail('email-1', ['name' => 'Welcome', 'status' => 'sent']);
    DB::connection('legacy')->table('ga_lmia_course_emails_c')->insert([
        'id' => 'rel-j-1', 'ga_lmia_course_emailsga_lmia_course_ida' => $lead->id, 'ga_lmia_course_emailsemails_idb' => 'email-1',
    ]);
    attachEmailAddress('email-1', 'from', 'agent@example.com');
    attachEmailAddress('email-1', 'to', 'client@example.com');
    attachEmailAddress('email-1', 'cc', 'manager@example.com');

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);

    $email = Email::withoutGlobalScopes()->find('email-1');
    expect($email)->not->toBeNull()
        ->and($email->subject_type)->toBe(Lead::class)
        ->and($email->subject_id)->toBe($lead->id)
        ->and($email->from_address)->toBe('agent@example.com')
        ->and($email->to_addresses)->toBe(['client@example.com'])
        ->and($email->cc_addresses)->toBe(['manager@example.com'])
        ->and($email->status)->toBe('sent');
});

it('resolves via parent_type using the current module name', function () {
    $company = Company::factory()->create();
    insertLegacyEmail('email-2', ['parent_type' => 'GA_Companies', 'parent_id' => $company->id]);
    attachEmailAddress('email-2', 'to', 'lead@example.com');

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);

    expect(Email::withoutGlobalScopes()->find('email-2')->subject_type)->toBe(Company::class);
});

it('resolves via a historical pre-rename alias when parent_id genuinely matches, and skips it when it does not', function () {
    $company = Company::factory()->create();
    insertLegacyEmail('email-3', ['parent_type' => 'hamid_companies', 'parent_id' => $company->id]);
    insertLegacyEmail('email-4', ['parent_type' => 'hamid_companies', 'parent_id' => 'no-such-company']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);

    expect(Email::withoutGlobalScopes()->find('email-3'))->not->toBeNull()
        ->and(Email::withoutGlobalScopes()->find('email-4'))->toBeNull();
});

it('maps legacy draft status to draft and everything else to sent', function () {
    $company = Company::factory()->create();
    insertLegacyEmail('email-5', ['parent_type' => 'GA_Companies', 'parent_id' => $company->id, 'status' => 'draft']);
    insertLegacyEmail('email-6', ['parent_type' => 'GA_Companies', 'parent_id' => $company->id, 'status' => 'replied']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);

    expect(Email::withoutGlobalScopes()->find('email-5')->status)->toBe('draft')
        ->and(Email::withoutGlobalScopes()->find('email-6')->status)->toBe('sent');
});

it('falls back to emails_text.from_addr, then a migrated.invalid placeholder, when no from relationship exists', function () {
    $company = Company::factory()->create();
    insertLegacyEmail('email-7', ['parent_type' => 'GA_Companies', 'parent_id' => $company->id]);
    DB::connection('legacy')->table('emails_text')->insert(['email_id' => 'email-7', 'from_addr' => 'legacy@example.com']);
    insertLegacyEmail('email-8', ['parent_type' => 'GA_Companies', 'parent_id' => $company->id]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);

    expect(Email::withoutGlobalScopes()->find('email-7')->from_address)->toBe('legacy@example.com')
        ->and(Email::withoutGlobalScopes()->find('email-8')->from_address)->toBe('email-8@migrated.invalid')
        ->and(Email::withoutGlobalScopes()->find('email-8')->to_addresses)->toBe([]);
});

it('re-runs idempotently without duplicating the row', function () {
    $company = Company::factory()->create();
    insertLegacyEmail('email-9', ['parent_type' => 'GA_Companies', 'parent_id' => $company->id]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'activities_emails'])->assertExitCode(0);

    expect(Email::withoutGlobalScopes()->count())->toBe(1);
});

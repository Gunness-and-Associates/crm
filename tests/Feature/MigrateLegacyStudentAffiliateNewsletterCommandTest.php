<?php

use App\Models\Affiliate;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Students, Affiliates and NewsletterSubscriber are all single-table
 * Contactable entities (Z-6.2 part 3) — same self-contained sqlite-fixture
 * approach as the Company/Lead ETL tests.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('ga_hq_students', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('get_started')->nullable();
        $table->string('status')->nullable();
        $table->string('how_hear')->nullable();
    });
    Schema::connection('legacy')->create('ga_hq_students_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->boolean('hot_lead_c')->default(false);
        $table->boolean('warm_lead_c')->default(false);
    });

    Schema::connection('legacy')->create('ga_affiliate', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('username')->nullable();
        $table->string('affiliate_link')->nullable();
    });
    Schema::connection('legacy')->create('ga_affiliate_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->string('commission_c')->nullable();
        $table->string('status_c')->nullable();
    });

    Schema::connection('legacy')->create('ga_newsletter_subscriber', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('status')->nullable();
        $table->string('source')->nullable();
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

it('migrates a legacy student with hot/warm flags from the cstm sidecar', function () {
    DB::connection('legacy')->table('ga_hq_students')->insert([
        'id' => 'student-1', 'first_name' => 'Amina', 'get_started' => 'yes', 'status' => 'Active', 'how_hear' => 'Google',
    ]);
    DB::connection('legacy')->table('ga_hq_students_cstm')->insert(['id_c' => 'student-1', 'hot_lead_c' => true]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'students'])->assertExitCode(0);

    $student = Student::withoutGlobalScopes()->find('student-1');
    expect($student)->not->toBeNull()
        ->and($student->get_started)->toBe('yes')
        ->and($student->how_hear)->toBe('Google')
        ->and($student->hot_lead)->toBeTrue()
        ->and($student->warm_lead)->toBeFalse();
});

it('disambiguates a duplicate affiliate username so both rows migrate', function () {
    DB::connection('legacy')->table('ga_affiliate')->insert([
        ['id' => 'affiliate-old', 'username' => 'lmia_partner', 'deleted' => true],
        ['id' => 'affiliate-new', 'username' => 'lmia_partner', 'deleted' => false],
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'affiliates'])->assertExitCode(0);

    expect(Affiliate::withoutGlobalScopes()->find('affiliate-new')->username)->toBe('lmia_partner')
        ->and(Affiliate::withoutGlobalScopes()->find('affiliate-old')->username)->toBe('lmia_partner-affiliat');
});

it('defaults a newsletter subscriber with no legacy status to subscribed', function () {
    DB::connection('legacy')->table('ga_newsletter_subscriber')->insert(['id' => 'sub-1', 'first_name' => 'Sam']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'newsletter_subscribers'])->assertExitCode(0);

    expect(NewsletterSubscriber::withoutGlobalScopes()->find('sub-1')->status)->toBe('subscribed');
});

it('re-runs all three idempotently without duplicating rows', function () {
    DB::connection('legacy')->table('ga_hq_students')->insert(['id' => 'student-2']);
    DB::connection('legacy')->table('ga_affiliate')->insert(['id' => 'affiliate-1']);
    DB::connection('legacy')->table('ga_newsletter_subscriber')->insert(['id' => 'sub-2']);

    foreach (['students', 'affiliates', 'newsletter_subscribers'] as $key) {
        $this->artisan('crm:migrate-legacy', ['--only' => $key])->assertExitCode(0);
        $this->artisan('crm:migrate-legacy', ['--only' => $key])->assertExitCode(0);
    }

    expect(Student::withoutGlobalScopes()->count())->toBe(1)
        ->and(Affiliate::withoutGlobalScopes()->count())->toBe(1)
        ->and(NewsletterSubscriber::withoutGlobalScopes()->count())->toBe(1);
});

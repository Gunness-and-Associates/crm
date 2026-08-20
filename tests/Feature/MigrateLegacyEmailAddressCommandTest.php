<?php

use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\EmailAddressRelation;
use App\Models\Lead;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTruncation::class);

/**
 * Email addresses (Z-6.2, final activities-adjacent step) -- backfills the
 * real EmailAddress/email_address_relations records from email_addr_bean_rel,
 * superseding the single denormalised primary_email string every Contactable
 * transformer already sets via RecoversLegacyEmail.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('email_addr_bean_rel', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('bean_id', 36)->nullable();
        $table->string('bean_module')->nullable();
        $table->string('email_address_id', 36)->nullable();
        $table->boolean('primary_address')->default(false);
        $table->boolean('deleted')->default(false);
    });

    Schema::connection('legacy')->create('email_addresses', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_address')->nullable();
        $table->boolean('invalid_email')->default(false);
        $table->boolean('opt_out')->default(false);
        $table->boolean('deleted')->default(false);
    });
});

function insertLegacyEmailAddress(string $id, string $email, array $overrides = []): void
{
    DB::connection('legacy')->table('email_addresses')->insert(array_merge(['id' => $id, 'email_address' => $email], $overrides));
}

function insertBeanRel(string $id, string $beanId, string $beanModule, string $emailAddressId, bool $primary = false): void
{
    DB::connection('legacy')->table('email_addr_bean_rel')->insert([
        'id' => $id, 'bean_id' => $beanId, 'bean_module' => $beanModule, 'email_address_id' => $emailAddressId, 'primary_address' => $primary,
    ]);
}

it('links a real bean to its email address, marks it primary and syncs primary_email onto the owner', function () {
    $company = Company::factory()->create();
    insertLegacyEmailAddress('addr-1', 'contact@example.com');
    insertBeanRel('rel-1', $company->id, 'GA_Companies', 'addr-1', primary: true);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    $relation = EmailAddressRelation::withoutGlobalScopes()->find('rel-1');
    expect($relation)->not->toBeNull()
        ->and($relation->related_type)->toBe(Company::class)
        ->and($relation->related_id)->toBe($company->id)
        ->and($relation->is_primary)->toBeTrue()
        ->and($relation->emailAddress->email)->toBe('contact@example.com')
        ->and($company->refresh()->primary_email)->toBe('contact@example.com');
});

it('skips a bean_id that does not resolve to any migrated record', function () {
    insertLegacyEmailAddress('addr-2', 'ghost@example.com');
    insertBeanRel('rel-2', 'no-such-lead-id', 'GA_GALead', 'addr-2', primary: true);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    expect(EmailAddressRelation::withoutGlobalScopes()->find('rel-2'))->toBeNull();
});

it('skips a bean_module with no migrated target entirely, never guessing one', function () {
    $lead = Lead::factory()->create();
    insertLegacyEmailAddress('addr-3', 'stock@example.com');
    insertBeanRel('rel-3', $lead->id, 'Leads', 'addr-3', primary: true);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    expect(EmailAddressRelation::withoutGlobalScopes()->find('rel-3'))->toBeNull();
});

it('forces exactly one relation primary when a bean has emails but none flagged primary_address', function () {
    $company = Company::factory()->create();
    insertLegacyEmailAddress('addr-4', 'first@example.com');
    insertLegacyEmailAddress('addr-5', 'second@example.com');
    insertBeanRel('rel-4', $company->id, 'GA_Companies', 'addr-4', primary: false);
    insertBeanRel('rel-5', $company->id, 'GA_Companies', 'addr-5', primary: false);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    $primaryCount = EmailAddressRelation::withoutGlobalScopes()
        ->where('related_type', Company::class)->where('related_id', $company->id)
        ->where('is_primary', true)->count();
    expect($primaryCount)->toBe(1)
        ->and($company->refresh()->primary_email)->not->toBeNull();
});

it('skips a duplicate bean+address pair once the first occurrence already linked it', function () {
    $company = Company::factory()->create();
    insertLegacyEmailAddress('addr-6', 'dup@example.com');
    insertBeanRel('rel-6', $company->id, 'GA_Companies', 'addr-6', primary: true);
    insertBeanRel('rel-7', $company->id, 'GA_Companies', 'addr-6', primary: true);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    expect(EmailAddressRelation::withoutGlobalScopes()->find('rel-6'))->not->toBeNull()
        ->and(EmailAddressRelation::withoutGlobalScopes()->find('rel-7'))->toBeNull()
        ->and(EmailAddress::withoutGlobalScopes()->where('email', 'dup@example.com')->count())->toBe(1);
});

it('carries invalid_email and opt_out flags onto the EmailAddress record', function () {
    $company = Company::factory()->create();
    insertLegacyEmailAddress('addr-8', 'bad@example.com', ['invalid_email' => true, 'opt_out' => true]);
    insertBeanRel('rel-8', $company->id, 'GA_Companies', 'addr-8', primary: true);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    $address = EmailAddress::withoutGlobalScopes()->where('email', 'bad@example.com')->first();
    expect($address->is_invalid)->toBeTrue()
        ->and($address->opted_out)->toBeTrue();
});

it('re-runs idempotently without duplicating the relation', function () {
    $company = Company::factory()->create();
    insertLegacyEmailAddress('addr-9', 'idempotent@example.com');
    insertBeanRel('rel-9', $company->id, 'GA_Companies', 'addr-9', primary: true);

    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'email_addresses'])->assertExitCode(0);

    expect(EmailAddressRelation::withoutGlobalScopes()->count())->toBe(1)
        ->and(EmailAddress::withoutGlobalScopes()->count())->toBe(1);
});

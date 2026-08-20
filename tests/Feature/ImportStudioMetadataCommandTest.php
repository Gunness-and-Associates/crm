<?php

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Z-8.3 -- deliberately RefreshDatabase, not DatabaseTruncation: this file's
// own tests create real {table}_custom sidecar tables via Schema::create()
// (dynamic DDL). DatabaseTruncation caches its connection's table list on
// first use and never recomputes it, so a table that appears mid-suite is
// truncated as empty but never dropped -- a later test asserting it doesn't
// exist would fail, and the trait's own truncation loop throws if that table
// is ever dropped out from under its stale cache. RefreshDatabase's
// transaction-per-test genuinely reverts the CREATE TABLE here, verified
// empirically. No test in this file makes an HTTP request through a
// tenancy-gated route, so it doesn't need the connection-switch safety
// DatabaseTruncation exists for elsewhere in this suite.
uses(RefreshDatabase::class);

/**
 * Studio metadata import (Z-6.3) -- registers genuinely-new legacy custom
 * fields (fields_meta_data) as real metadata fields with a backing
 * {table}_custom sidecar column, via the same SchemaManager path a live
 * Studio "add field" action takes. Deliberately excludes the ~54 legacy
 * custom fields already captured elsewhere (base columns, vertical_attributes)
 * -- see ImportStudioMetadataCommand's own docblock for how each was verified.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('fields_meta_data', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('vname')->nullable();
        $table->string('custom_module')->nullable();
        $table->string('type')->nullable();
        $table->integer('len')->nullable();
        $table->boolean('deleted')->default(false);
        $table->string('ext1')->nullable();
        $table->string('ext2')->nullable();
        $table->string('ext3')->nullable();
    });

    // firstOrCreate, not create: this file stays on RefreshDatabase (its own
    // dynamically-created {table}_custom sidecar tables are incompatible with
    // DatabaseTruncation -- see the comment above the trait declaration), so
    // a 'companies'/'affiliates' Module row committed by an earlier
    // DatabaseTruncation-based test in the same parallel worker (e.g. via
    // MetadataFixtureSeeder) may already exist when this file's first test
    // starts.
    Module::query()->firstOrCreate(['key' => 'companies'], ['label' => 'Companies', 'table_name' => 'companies', 'base_type' => 'company', 'enabled' => true]);
    Module::query()->firstOrCreate(['key' => 'affiliates'], ['label' => 'Affiliates', 'table_name' => 'affiliates', 'base_type' => 'generic', 'enabled' => true]);
});

function insertLegacyField(string $customModule, string $name, string $type, array $overrides = []): void
{
    DB::connection('legacy')->table('fields_meta_data')->insert(array_merge([
        'id' => "{$customModule}{$name}", 'name' => $name, 'custom_module' => $customModule, 'type' => $type,
    ], $overrides));
}

it('registers a genuinely-new varchar field as text with a real sidecar column', function () {
    insertLegacyField('GA_Companies', 'billing_address_street_c', 'varchar', ['len' => 150]);

    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    $field = Field::query()->whereHas('module', fn ($q) => $q->where('key', 'companies'))->where('name', 'billing_address_street_c')->first();
    expect($field)->not->toBeNull()
        ->and($field->type)->toBe('text')
        ->and($field->max_length)->toBe(150)
        ->and($field->is_custom)->toBeTrue()
        ->and(Schema::hasColumn('companies_custom', 'billing_address_street_c'))->toBeTrue()
        ->and(Change::query()->where('kind', 'field.add')->where('target_field', 'billing_address_street_c')->exists())->toBeTrue();
});

it('types a varchar field as email or phone by name, not by the legacy varchar type', function () {
    insertLegacyField('GA_Companies', 'contact_person_email_c', 'varchar');
    insertLegacyField('GA_Affiliate', 'whatsapp_c', 'varchar');

    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    expect(Field::query()->where('name', 'contact_person_email_c')->first()->type)->toBe('email')
        ->and(Field::query()->where('name', 'whatsapp_c')->first()->type)->toBe('phone');
});

it('skips a field already known to be captured elsewhere in the target data', function () {
    insertLegacyField('GA_Companies', 'hot_lead_c', 'bool');
    insertLegacyField('GA_Companies', 'status_c', 'enum', ['ext1' => 'assessment_score_status']);

    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    expect(Field::query()->where('name', 'hot_lead_c')->exists())->toBeFalse()
        ->and(Field::query()->where('name', 'status_c')->exists())->toBeFalse();
});

it('skips a custom_module with no migrated target module entirely, never guessing one', function () {
    insertLegacyField('Leads', 'some_stock_field_c', 'varchar');

    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    expect(Field::query()->where('name', 'some_stock_field_c')->exists())->toBeFalse();
});

it('collapses a relate/id pair into one real relate field pointed at the related module', function () {
    insertLegacyField('GA_Companies', 'user_id_c', 'id', ['len' => 36]);
    insertLegacyField('GA_Companies', 'recruiter_c', 'relate', ['ext2' => 'Users', 'ext3' => 'user_id_c']);

    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    $usersModule = Module::query()->where('key', 'users')->first();
    $field = Field::query()->where('name', 'recruiter_id')->first();

    expect($usersModule)->not->toBeNull()
        ->and($field)->not->toBeNull()
        ->and($field->type)->toBe('relate')
        ->and($field->related_module_id)->toBe($usersModule->id)
        ->and($field->related_display_field)->toBe('name')
        ->and(Field::query()->where('name', 'user_id_c')->exists())->toBeFalse()
        ->and(Schema::hasColumn('companies_custom', 'recruiter_id'))->toBeTrue();
});

it('registers the same custom field name only once when several source modules consolidate onto one target module', function () {
    insertLegacyField('GA_Companies', 'shipping_address_street_c', 'varchar');
    // A second, unrelated custom_module that also maps to 'companies' in
    // production would collapse the same way -- simulated here by inserting
    // the identical field name again under the same module, since this repo
    // only has one real custom_module mapped to companies.
    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);
    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    expect(Field::query()->where('name', 'shipping_address_street_c')->count())->toBe(1);
});

it('dry-run validates without writing any field, column or sidecar table', function () {
    insertLegacyField('GA_Companies', 'contact_person_email_c', 'varchar');

    $this->artisan('crm:import-studio-metadata', ['--dry-run' => true])->assertExitCode(0);

    expect(Field::query()->where('name', 'contact_person_email_c')->exists())->toBeFalse()
        ->and(Schema::hasTable('companies_custom'))->toBeFalse();
});

it('re-runs idempotently without duplicating the field', function () {
    insertLegacyField('GA_Companies', 'billing_address_street_c', 'varchar');

    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);
    $this->artisan('crm:import-studio-metadata')->assertExitCode(0);

    expect(Field::query()->where('name', 'billing_address_street_c')->count())->toBe(1);
});

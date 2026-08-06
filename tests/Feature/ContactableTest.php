<?php

use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    if (! Schema::hasTable('contactable_fixtures_custom')) {
        Schema::create('contactable_fixtures_custom', function (Blueprint $table) {
            $table->uuid('id_c')->primary();
            $table->string('favourite_colour')->nullable();
            $table->boolean('newsletter_opt_in')->nullable();
        });
    }
});

it('has every contactable base column', function () {
    foreach ([
        'salutation', 'first_name', 'last_name', 'title', 'department', 'description',
        'do_not_call', 'phone_home', 'phone_mobile', 'phone_work', 'phone_other', 'phone_fax',
        'whatsapp_number', 'primary_address_street', 'primary_address_city', 'alt_address_street',
        'lawful_basis', 'date_reviewed', 'lawful_basis_source', 'primary_email',
        'assigned_user_id', 'created_by', 'modified_by',
    ] as $column) {
        expect(Schema::hasColumn('contactable_fixtures', $column))->toBeTrue();
    }
});

it('uses a char(36) uuid primary key and soft deletes', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    expect($record->getIncrementing())->toBeFalse()
        ->and($record->id)->toMatch('/^[0-9a-f-]{36}$/i');

    $record->delete();
    expect(ContactableFixture::find($record->id))->toBeNull()
        ->and(ContactableFixture::withTrashed()->find($record->id))->not->toBeNull();
});

it('casts do_not_call to boolean and date_reviewed to a date', function () {
    $record = ContactableFixture::create([
        'first_name' => 'Amina', 'do_not_call' => 1, 'date_reviewed' => '2026-01-15',
    ]);

    expect($record->do_not_call)->toBeTrue()
        ->and($record->date_reviewed)->toBeInstanceOf(Carbon\Carbon::class);
});

it('builds the full name helper', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    expect($record->fullName())->toBe('Amina Khan');
});

it('reads a custom field from the sidecar transparently', function () {
    $module = Module::factory()->create(['key' => 'contactable_fixtures', 'table_name' => 'contactable_fixtures']);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'favourite_colour', 'type' => 'text']);

    $record = ContactableFixture::create(['first_name' => 'Amina']);
    DB::table('contactable_fixtures_custom')->insert(['id_c' => $record->id, 'favourite_colour' => 'teal']);

    $fresh = ContactableFixture::find($record->id);
    expect($fresh->favourite_colour)->toBe('teal');
});

it('writes a custom field to the sidecar on save', function () {
    $module = Module::factory()->create(['key' => 'contactable_fixtures', 'table_name' => 'contactable_fixtures']);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'favourite_colour', 'type' => 'text']);

    $record = ContactableFixture::create(['first_name' => 'Amina']);
    $record->favourite_colour = 'crimson';
    $record->save();

    expect(DB::table('contactable_fixtures_custom')->where('id_c', $record->id)->value('favourite_colour'))
        ->toBe('crimson');
});

it('derives a boolean cast for a custom field from the field-type contract', function () {
    $module = Module::factory()->create(['key' => 'contactable_fixtures', 'table_name' => 'contactable_fixtures']);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'newsletter_opt_in', 'type' => 'bool']);

    $record = ContactableFixture::create(['first_name' => 'Amina']);
    $record->newsletter_opt_in = 1;
    $record->save();

    $fresh = ContactableFixture::find($record->id);
    expect($fresh->newsletter_opt_in)->toBeTrue();
});

it('merges custom field names into fillable', function () {
    $module = Module::factory()->create(['key' => 'contactable_fixtures', 'table_name' => 'contactable_fixtures']);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'favourite_colour', 'type' => 'text']);

    $record = new ContactableFixture;
    expect($record->getFillable())->toContain('favourite_colour')
        ->and($record->getFillable())->toContain('first_name');
});

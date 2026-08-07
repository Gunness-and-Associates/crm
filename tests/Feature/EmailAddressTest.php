<?php

use App\Models\EmailAddress;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ContactableFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Z-2.3 wires ContactableFixture into the ACL engine (HasAcl); these tests are not
    // about ACL, so act as an admin (unrestricted) throughout.
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    if (! Schema::hasTable('contactable_fixtures')) {
        Schema::create('contactable_fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->contactable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
});

it('attaches an email address and denormalises primary_email onto the owner', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);

    $record->attachEmailAddress('Amina@Example.com', primary: true);

    expect($record->emailAddresses)->toHaveCount(1)
        ->and(EmailAddress::query()->where('email', 'amina@example.com')->exists())->toBeTrue()
        ->and($record->fresh()->primary_email)->toBe('amina@example.com');
});

it('re-points primary_email when a different address is marked primary', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);
    $record->attachEmailAddress('first@example.com', primary: true);
    $record->attachEmailAddress('second@example.com', primary: true);

    expect($record->fresh()->primary_email)->toBe('second@example.com')
        ->and($record->emailAddresses)->toHaveCount(2)
        ->and($record->primaryEmailAddress()->email)->toBe('second@example.com');
});

it('clears primary_email when the primary relation is removed', function () {
    $record = ContactableFixture::create(['first_name' => 'Amina']);
    $record->attachEmailAddress('only@example.com', primary: true);

    $record->emailAddresses()->wherePivot('is_primary', true)->first()->pivot->delete();

    expect($record->fresh()->primary_email)->toBeNull();
});

it('reuses one EmailAddress row across multiple owners', function () {
    $first = ContactableFixture::create(['first_name' => 'Amina']);
    $second = ContactableFixture::create(['first_name' => 'Bilal']);

    $first->attachEmailAddress('shared@example.com', primary: true);
    $second->attachEmailAddress('shared@example.com', primary: true);

    expect(EmailAddress::query()->where('email', 'shared@example.com')->count())->toBe(1);
});

<?php

use App\Models\Lead;
use App\Models\User;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('lists modules with a record count', function () {
    Lead::factory()->count(2)->create();

    $response = $this->getJson('/api/v1/meta/modules');

    $response->assertOk();
    $leads = collect($response->json('data'))->firstWhere('key', 'leads');

    expect($leads)->not->toBeNull()
        ->and($leads['count'])->toBe(2);
});

it('lists every field for a module, with its flags and enum options', function () {
    $response = $this->getJson('/api/v1/meta/modules/leads/fields');

    $response->assertOk();
    $vertical = collect($response->json('data'))->firstWhere('name', 'vertical');

    expect($vertical)->not->toBeNull()
        ->and($vertical['type'])->toBe('enum')
        ->and($vertical['filterable'])->toBeTrue()
        ->and($vertical['options'])->not->toBeEmpty();
});

it('returns 404 for an unregistered module\'s fields', function () {
    $this->getJson('/api/v1/meta/modules/not-a-real-module/fields')->assertStatus(404);
});

it('resolves an option list by key', function () {
    $response = $this->getJson('/api/v1/meta/option-lists/lead_stage');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('value'))->toContain('new');
});

it('returns 404 for an unknown option list key', function () {
    $this->getJson('/api/v1/meta/option-lists/not-a-real-list')->assertStatus(404);
});

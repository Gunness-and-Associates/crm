<?php

use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);
});

it('blocks create with 403 forbidden when the caller\'s role has no edit access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::None, 'edit');
    actingAsApiUser($user, ['leads:write']);

    $this->postJson('/api/v1/leads', ['full_name' => 'Amina Khan'])
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});

it('allows create once the caller\'s role has edit access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'edit');
    actingAsApiUser($user, ['leads:write']);

    $this->postJson('/api/v1/leads', ['full_name' => 'Amina Khan'])->assertStatus(201);
});

it('blocks update with 403 (not 404) on a record the caller can see but not edit', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    grantAccess($editor, 'leads', AccessLevel::All, 'view');
    grantAccess($editor, 'leads', AccessLevel::Owner, 'edit');
    $lead = Lead::factory()->create(['assigned_user_id' => $owner->id]);

    actingAsApiUser($editor, ['leads:read', 'leads:write']);

    // view=All means the record is visible (not a 404)...
    $this->getJson("/api/v1/leads/{$lead->id}")->assertOk();
    // ...but edit=Owner means this non-owned record can't be mutated.
    $this->patchJson("/api/v1/leads/{$lead->id}", ['stage' => 'qualified'])
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});

it('allows update of a record the caller owns under edit=Owner', function () {
    $editor = User::factory()->create();
    grantAccess($editor, 'leads', AccessLevel::All, 'view');
    grantAccess($editor, 'leads', AccessLevel::Owner, 'edit');
    $lead = Lead::factory()->create(['assigned_user_id' => $editor->id]);

    actingAsApiUser($editor, ['leads:read', 'leads:write']);

    $this->patchJson("/api/v1/leads/{$lead->id}", ['stage' => 'qualified'])->assertOk();
});

it('blocks delete with 403 when the caller\'s role has no delete access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    grantAccess($user, 'leads', AccessLevel::None, 'delete');
    $lead = Lead::factory()->create(['assigned_user_id' => $user->id]);

    actingAsApiUser($user, ['leads:read', 'leads:delete']);

    $this->deleteJson("/api/v1/leads/{$lead->id}")
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});

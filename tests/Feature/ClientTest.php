<?php

use App\Models\Client;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes a client', function () {
    $client = Client::factory()->create(['first_name' => 'Amina']);

    expect(Client::find($client->id))->not->toBeNull();

    $client->update(['client_status' => 'documents']);
    expect($client->fresh()->client_status)->toBe('documents');

    $client->delete();
    expect(Client::find($client->id))->toBeNull()
        ->and(Client::withTrashed()->find($client->id))->not->toBeNull();
});

it('relates a client to its assigned user', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['assigned_user_id' => $user->id]);

    expect($client->assignedUser->is($user))->toBeTrue();
});

it('scopes clients to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'clients', AccessLevel::Owner);

    Client::factory()->create(['assigned_user_id' => $owner->id]);
    Client::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(Client::query()->count())->toBe(1);
});

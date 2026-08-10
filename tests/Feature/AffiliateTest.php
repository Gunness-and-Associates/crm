<?php

use App\Models\Affiliate;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes an affiliate', function () {
    $affiliate = Affiliate::factory()->create(['first_name' => 'Amina']);

    expect(Affiliate::find($affiliate->id))->not->toBeNull();

    $affiliate->update(['status' => 'inactive']);
    expect($affiliate->fresh()->status)->toBe('inactive');

    $affiliate->delete();
    expect(Affiliate::find($affiliate->id))->toBeNull()
        ->and(Affiliate::withTrashed()->find($affiliate->id))->not->toBeNull();
});

it('relates an affiliate to its assigned user', function () {
    $user = User::factory()->create();
    $affiliate = Affiliate::factory()->create(['assigned_user_id' => $user->id]);

    expect($affiliate->assignedUser->is($user))->toBeTrue();
});

it('scopes affiliates to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'affiliates', AccessLevel::Owner);

    Affiliate::factory()->create(['assigned_user_id' => $owner->id]);
    Affiliate::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(Affiliate::query()->count())->toBe(1);
});

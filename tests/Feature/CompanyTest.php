<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes a company', function () {
    $company = Company::factory()->create(['first_name' => 'Acme Ltd']);

    expect(Company::find($company->id))->not->toBeNull();

    $company->update(['industry' => 'Construction']);
    expect($company->fresh()->industry)->toBe('Construction');

    $company->delete();
    expect(Company::find($company->id))->toBeNull()
        ->and(Company::withTrashed()->find($company->id))->not->toBeNull();
});

it('relates a company to its assigned user', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['assigned_user_id' => $user->id]);

    expect($company->assignedUser->is($user))->toBeTrue();
});

it('scopes companies to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'companies', AccessLevel::Owner);

    Company::factory()->create(['assigned_user_id' => $owner->id]);
    Company::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(Company::query()->count())->toBe(1);
});

<?php

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Lead;
use App\Models\Metadata\OptionList;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes a lead', function () {
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'vertical' => LeadVertical::Refugee]);

    expect(Lead::find($lead->id))->not->toBeNull()
        ->and($lead->vertical)->toBe(LeadVertical::Refugee)
        ->and($lead->stage)->toBe(LeadStage::New);

    $lead->update(['stage' => LeadStage::Qualified]);
    expect($lead->fresh()->stage)->toBe(LeadStage::Qualified);

    $lead->delete();
    expect(Lead::find($lead->id))->toBeNull()
        ->and(Lead::withTrashed()->find($lead->id))->not->toBeNull();
});

it('stores vertical-specific answers in vertical_attributes', function () {
    $lead = Lead::factory()->create([
        'vertical' => LeadVertical::Refugee,
        'vertical_attributes' => ['current_situation' => 'in_transit', 'afraid_to_return' => true],
    ]);

    // toEqual, not toBe: MySQL's native JSON type does not guarantee key order on
    // storage (unlike MariaDB's LONGTEXT-backed JSON), so this must not depend on it.
    expect($lead->fresh()->vertical_attributes)->toEqual(['current_situation' => 'in_transit', 'afraid_to_return' => true]);
});

it('relates a lead to its assigned user', function () {
    $user = User::factory()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $user->id]);

    expect($lead->assignedUser->is($user))->toBeTrue();
});

it('scopes leads to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'leads', AccessLevel::Owner);

    Lead::factory()->create(['assigned_user_id' => $owner->id]);
    Lead::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(Lead::query()->count())->toBe(1);
});

it('has all 16 verticals registered in the option list', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $list = OptionList::query()->where('key', 'lead_vertical')->first();

    expect($list->items)->toHaveCount(16);
});

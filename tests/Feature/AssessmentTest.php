<?php

use App\Models\Assessment;
use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('creates, reads, updates and soft-deletes an assessment', function () {
    $assessment = Assessment::factory()->create(['crs_score' => 410]);

    expect(Assessment::find($assessment->id))->not->toBeNull();

    $assessment->update(['status' => 'completed']);
    expect($assessment->fresh()->status)->toBe('completed');

    $assessment->delete();
    expect(Assessment::find($assessment->id))->toBeNull()
        ->and(Assessment::withTrashed()->find($assessment->id))->not->toBeNull();
});

it('relates an assessment to a lead and the assessing user', function () {
    $lead = Lead::factory()->create();
    $assessor = User::factory()->create();
    $assessment = Assessment::factory()->create(['lead_id' => $lead->id, 'assessed_by' => $assessor->id]);

    expect($assessment->lead->is($lead))->toBeTrue()
        ->and($assessment->assessedBy->is($assessor))->toBeTrue();
});

it('stores the full CRS/FSW factor breakdown in scores', function () {
    $assessment = Assessment::factory()->create([
        'scores' => ['fsw_age_score' => 12, 'fsw_language_score' => 24],
    ]);

    // toEqual, not toBe: MySQL's native JSON type does not guarantee key order on
    // storage (unlike MariaDB's LONGTEXT-backed JSON), so this must not depend on it.
    expect($assessment->fresh()->scores)->toEqual(['fsw_age_score' => 12, 'fsw_language_score' => 24]);
});

it('scopes assessments to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'assessments', AccessLevel::Owner);

    Assessment::factory()->create(['assigned_user_id' => $owner->id]);
    Assessment::factory()->create(['assigned_user_id' => $other->id]);

    $this->actingAs($owner);

    expect(Assessment::query()->count())->toBe(1);
});

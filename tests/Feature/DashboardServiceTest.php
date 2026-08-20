<?php

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use App\Support\DashboardService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('groups hot and warm leads by vertical', function () {
    Lead::factory()->create(['vertical' => LeadVertical::Refugee, 'hot_lead' => true, 'warm_lead' => false]);
    Lead::factory()->create(['vertical' => LeadVertical::Refugee, 'hot_lead' => false, 'warm_lead' => true]);
    Lead::factory()->create(['vertical' => LeadVertical::StudyPermit, 'hot_lead' => false, 'warm_lead' => false]);

    $rows = collect(app(DashboardService::class)->hotAndWarmByVertical())->keyBy('vertical');

    expect($rows[LeadVertical::Refugee->value]['hot'])->toBe(1)
        ->and($rows[LeadVertical::Refugee->value]['warm'])->toBe(1)
        ->and($rows[LeadVertical::StudyPermit->value]['hot'])->toBe(0);
});

it('reports every stage, including stages with zero leads', function () {
    Lead::factory()->create(['stage' => LeadStage::New]);
    Lead::factory()->create(['stage' => LeadStage::New]);
    Lead::factory()->create(['stage' => LeadStage::Qualified]);

    $rows = collect(app(DashboardService::class)->pipelineByStage())->keyBy('stage');

    expect($rows[LeadStage::New->value]['count'])->toBe(2)
        ->and($rows[LeadStage::Qualified->value]['count'])->toBe(1)
        ->and($rows[LeadStage::Lost->value]['count'])->toBe(0)
        ->and($rows)->toHaveCount(6);
});

it('lists leads due for a call today, excluding yesterday and tomorrow', function () {
    $today = Lead::factory()->create(['next_follow_up_at' => now()->addHours(2)]);
    Lead::factory()->create(['next_follow_up_at' => now()->subDay()]);
    Lead::factory()->create(['next_follow_up_at' => now()->addDay()]);

    $calls = app(DashboardService::class)->callsToMake();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['id'])->toBe($today->id);
});

it('flags overdue leads and clients as needing attention, not ones due today', function () {
    $overdueLead = Lead::factory()->create(['next_follow_up_at' => now()->subDay()]);
    $overdueClient = Client::factory()->create(['next_action_at' => now()->subDays(2)]);
    Lead::factory()->create(['next_follow_up_at' => now()->addHour()]);

    $attention = app(DashboardService::class)->attentionNeeded();
    $ids = collect($attention)->pluck('id');

    expect($ids)->toContain($overdueLead->id)
        ->and($ids)->toContain($overdueClient->id)
        ->and($attention)->toHaveCount(2);
});

it('scopes every widget to what the signed-in user is allowed to see', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($owner, 'leads', AccessLevel::Owner);

    Lead::factory()->create(['assigned_user_id' => $owner->id, 'vertical' => LeadVertical::Refugee, 'hot_lead' => true]);
    Lead::factory()->create(['assigned_user_id' => $other->id, 'vertical' => LeadVertical::Refugee, 'hot_lead' => true]);

    $this->actingAs($owner);

    $rows = collect(app(DashboardService::class)->hotAndWarmByVertical())->keyBy('vertical');

    expect($rows[LeadVertical::Refugee->value]['hot'])->toBe(1);
});

it('caches a widget result for the configured window', function () {
    Lead::factory()->create(['stage' => LeadStage::New]);

    $service = app(DashboardService::class);
    $first = collect($service->pipelineByStage())->keyBy('stage');
    expect($first[LeadStage::New->value]['count'])->toBe(1);

    Lead::factory()->create(['stage' => LeadStage::New]);
    $second = collect($service->pipelineByStage())->keyBy('stage');

    expect($second[LeadStage::New->value]['count'])->toBe(1);

    Cache::flush();
    $third = collect($service->pipelineByStage())->keyBy('stage');
    expect($third[LeadStage::New->value]['count'])->toBe(2);
});

<?php

use App\Models\Metadata\Module;
use App\Support\LayoutValidator;
use App\Support\MetadataRepository;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

it('compiles the seeded leads module and its option lists', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $meta = app(MetadataRepository::class)->compiled();

    expect($meta['modules'])->toHaveKey('leads')
        ->and($meta['modules']['leads']['fields'])
        ->toHaveKeys(['full_name', 'vertical', 'stage', 'primary_email', 'phone_mobile'])
        ->and($meta['option_lists'])->toHaveKeys(['lead_vertical', 'lead_stage'])
        ->and($meta['option_lists']['lead_stage']['items'])->toContain(['value' => 'follow_up', 'label' => 'Follow up']);
});

it('seeds layouts that satisfy the frozen contract', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $validator = app(LayoutValidator::class);

    $leads = Module::query()->where('key', 'leads')->firstOrFail();

    expect($leads->layouts)->toHaveCount(2);
    foreach ($leads->layouts as $layout) {
        expect($validator->errors($layout->definition))->toBe([]);
    }
});

it('rejects a layout that violates the contract', function () {
    $bad = [
        'version' => 1,
        'view' => 'list',
        'module' => 'leads',
        'content' => ['columns' => [['nope' => 'x']]],   // missing required 'field', extra prop
    ];

    expect(app(LayoutValidator::class)->valid($bad))->toBeFalse();
});

it('bumps the version on any metadata change', function () {
    $repo = app(MetadataRepository::class);
    $before = $repo->version();

    Module::factory()->create();

    expect($repo->version())->toBeGreaterThan($before);
});

it('caches the compiled structure per version', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $repo = app(MetadataRepository::class);

    expect($repo->compiled())->toBe($repo->compiled())
        ->and($repo->compiled()['version'])->toBe($repo->version());
});

it('invalidates the in-memory compiled() memo on bump(), so a change is visible within the same request', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $repo = app(MetadataRepository::class);

    $before = $repo->compiled();
    Module::factory()->create(['key' => 'a_new_module']); // triggers Module::saved -> bump()
    $after = $repo->compiled();

    expect($after['version'])->toBeGreaterThan($before['version'])
        ->and($after['modules'])->toHaveKey('a_new_module');
});

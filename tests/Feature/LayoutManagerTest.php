<?php

use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Support\LayoutManager;
use App\Support\MetadataRepository;
use App\Support\MetadataValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function layoutModule(): Module
{
    return Module::factory()->create(['key' => 'lm_test', 'table_name' => 'lm_test']);
}

function layoutDefinition(string $view = 'list', string $moduleKey = 'lm_test'): array
{
    return [
        'version' => 1,
        'view' => $view,
        'module' => $moduleKey,
        'content' => ['columns' => [['field' => 'full_name', 'priority' => 1]]],
    ];
}

it('drafts a new, unpublished, incrementing version', function () {
    $module = layoutModule();
    $manager = app(LayoutManager::class);

    $first = $manager->draft($module->key, 'list', layoutDefinition());
    $second = $manager->draft($module->key, 'list', layoutDefinition());

    expect($first->version)->toBe(1)
        ->and($first->is_published)->toBeFalse()
        ->and($second->version)->toBe(2)
        ->and($second->is_published)->toBeFalse();
});

it("rejects a draft whose definition's view or module does not match the request", function () {
    $module = layoutModule();
    $manager = app(LayoutManager::class);

    expect(fn () => $manager->draft($module->key, 'detail', layoutDefinition(view: 'list')))
        ->toThrow(MetadataValidationException::class)
        ->and(fn () => $manager->draft($module->key, 'list', layoutDefinition(moduleKey: 'other_module')))
        ->toThrow(MetadataValidationException::class);
});

it('rejects a draft that fails the frozen layout schema', function () {
    $module = layoutModule();
    $manager = app(LayoutManager::class);

    $bad = layoutDefinition();
    unset($bad['content']);

    expect(fn () => $manager->draft($module->key, 'list', $bad))
        ->toThrow(MetadataValidationException::class);
});

it('publish makes exactly one version live per module and view', function () {
    $module = layoutModule();
    $manager = app(LayoutManager::class);

    $v1 = $manager->draft($module->key, 'list', layoutDefinition());
    $manager->publish($v1->id);
    $v2 = $manager->draft($module->key, 'list', layoutDefinition());
    $manager->publish($v2->id);

    expect(Layout::query()->find($v1->id)->is_published)->toBeFalse()
        ->and(Layout::query()->find($v2->id)->is_published)->toBeTrue()
        ->and(Layout::query()->where('module_id', $module->id)->where('view', 'list')->where('is_published', true)->count())->toBe(1);
});

it('revert republishes an old definition as a brand-new version', function () {
    $module = layoutModule();
    $manager = app(LayoutManager::class);

    $v1 = $manager->draft($module->key, 'list', layoutDefinition());
    $manager->publish($v1->id);
    $v2 = $manager->draft($module->key, 'list', [
        ...layoutDefinition(),
        'content' => ['columns' => [['field' => 'primary_email', 'priority' => 1]]],
    ]);
    $manager->publish($v2->id);

    $reverted = $manager->revert($module->key, 'list', 1);

    expect($reverted->version)->toBe(3)
        ->and($reverted->is_published)->toBeTrue()
        ->and($reverted->definition)->toEqual($v1->definition)
        ->and(Layout::query()->find($v1->id)->is_published)->toBeFalse()
        ->and(Layout::query()->find($v2->id)->is_published)->toBeFalse();
});

it('serves only the published layout through the compiled metadata repository', function () {
    $module = layoutModule();
    $manager = app(LayoutManager::class);
    $repository = app(MetadataRepository::class);

    $v1 = $manager->draft($module->key, 'list', layoutDefinition());
    $manager->publish($v1->id);
    $manager->draft($module->key, 'list', [
        ...layoutDefinition(),
        'content' => ['columns' => [['field' => 'primary_email', 'priority' => 1]]],
    ]); // unpublished draft must not leak into the compiled registry

    $compiled = $repository->compiled();

    expect($compiled['modules']['lm_test']['layouts']['list'])->toEqual($v1->definition);
});

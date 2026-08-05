<?php

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses uuid keys across metadata tables', function () {
    foreach ([Module::factory()->create(), Field::factory()->create(), OptionList::factory()->create()] as $model) {
        expect($model->getIncrementing())->toBeFalse()
            ->and($model->getKey())->toMatch('/^[0-9a-f-]{36}$/i');
    }
});

it('relates fields and layouts to a module', function () {
    $module = Module::factory()->create();
    Field::factory()->count(3)->create(['module_id' => $module->id]);
    Layout::factory()->create(['module_id' => $module->id, 'view' => 'detail']);

    expect($module->fields)->toHaveCount(3)
        ->and($module->fields->first()->module->is($module))->toBeTrue()
        ->and($module->layouts)->toHaveCount(1);
});

it('relates option items to a list, ordered', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'b', 'sort_order' => 2]);
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'a', 'sort_order' => 1]);

    expect($list->items->pluck('value')->all())->toBe(['a', 'b']);
});

it('links a relate field to another module and an enum field to an option list', function () {
    $company = Module::factory()->create(['key' => 'companies']);
    $list = OptionList::factory()->create();

    $relate = Field::factory()->create(['type' => 'relate', 'related_module_id' => $company->id]);
    $enum = Field::factory()->create(['type' => 'enum', 'option_list_id' => $list->id]);

    expect($relate->relatedModule->is($company))->toBeTrue()
        ->and($enum->optionList->is($list))->toBeTrue();
});

it('casts validation and stores a change with an actor', function () {
    $field = Field::factory()->create(['validation' => ['nullable', 'string', 'max:255']]);
    expect($field->fresh()->validation)->toBe(['nullable', 'string', 'max:255']);

    $change = Change::factory()->create(['actor_id' => User::factory()->create()->id]);
    expect($change->actor)->not->toBeNull()
        ->and($change->payload)->toBeArray();
});

it('enforces one field name per module', function () {
    $module = Module::factory()->create();
    Field::factory()->create(['module_id' => $module->id, 'name' => 'status']);

    expect(fn () => Field::factory()->create(['module_id' => $module->id, 'name' => 'status']))
        ->toThrow(QueryException::class);
});

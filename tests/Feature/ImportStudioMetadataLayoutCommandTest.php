<?php

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Support\Etl\LegacyViewDefReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Studio metadata import, layout half (Z-6.3 part 2) -- mechanically
 * translates legacy listviewdefs/detailviewdefs/editviewdefs/searchdefs.php
 * (plain PHP array literals) into layout.schema.json-shaped `layouts` rows,
 * for the handful of target modules with a clean 1:1 legacy source module.
 * Consolidated entities (leads: 23 source modules; assessments: 2) are
 * deliberately excluded -- there is no single legacy view-def for those.
 */
function legacyRoot(): string
{
    return sys_get_temp_dir().'/studio_layout_test_'.getmypid();
}

function writeLegacyViewDef(string $module, string $file, string $phpArrayLiteral): void
{
    $dir = legacyRoot()."/modules/{$module}/metadata";
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/{$file}", "<?php\n{$phpArrayLiteral}\n");
}

beforeEach(function () {
    File::deleteDirectory(legacyRoot());
    config(['etl.legacy_php_root' => legacyRoot()]);
    app()->forgetInstance(LegacyViewDefReader::class);
});

afterEach(function () {
    File::deleteDirectory(legacyRoot());
});

function seedCompaniesModuleWithFields(): Module
{
    $module = Module::query()->create(['key' => 'companies', 'label' => 'Companies', 'table_name' => 'companies', 'base_type' => 'company', 'enabled' => true]);
    foreach (['full_name', 'primary_email', 'company_contact_status'] as $name) {
        Field::query()->create(['module_id' => $module->id, 'name' => $name, 'type' => 'text', 'label_key' => 'LBL_'.strtoupper($name), 'storage' => 'column']);
    }

    return $module;
}

it('translates a legacy listviewdefs.php into list-view columns, resolving names and picking one link column', function () {
    seedCompaniesModuleWithFields();
    writeLegacyViewDef('GA_Companies', 'listviewdefs.php', <<<'PHP'
$module_name = 'GA_Companies';
$listViewDefs[$module_name] = array(
    'NAME' => array('label' => 'LBL_NAME', 'link' => true, 'default' => true),
    'COMPANY_CONTACT_STATUS' => array('label' => 'LBL_STATUS', 'default' => true),
    'TICKER_SYMBOL' => array('label' => 'LBL_TICKER', 'default' => false),
);
PHP);

    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);

    $layout = Layout::query()->where('view', 'list')->first();
    expect($layout)->not->toBeNull()
        ->and($layout->is_published)->toBeFalse()
        ->and($layout->definition['content']['columns'])->toEqual([
            ['field' => 'full_name', 'priority' => 1, 'link' => true],
            ['field' => 'company_contact_status', 'priority' => 1],
        ]);
});

it('drops a row entirely unresolved, and a field-slot partially unresolved, in a detail panel', function () {
    seedCompaniesModuleWithFields();
    writeLegacyViewDef('GA_Companies', 'detailviewdefs.php', <<<'PHP'
$module_name = 'GA_Companies';
$viewdefs['GA_Companies'] = array(
    'DetailView' => array(
        'panels' => array(
            'LBL_CONTACT_INFORMATION' => array(
                array(array('name' => 'company_contact_status'), array('name' => 'ticker_symbol')),
                array(array('name' => 'assigned_client')),
            ),
        ),
    ),
);
PHP);

    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);

    $layout = Layout::query()->where('view', 'detail')->first();
    expect($layout)->not->toBeNull();
    $panels = $layout->definition['content']['panels'];
    expect($panels)->toHaveCount(1)
        ->and($panels[0]['key'])->toBe('contact_information')
        ->and($panels[0]['rows'])->toEqual([[['field' => 'company_contact_status']]]);
});

it('produces nothing for a consolidated module with no clean 1:1 legacy source', function () {
    $leads = Module::query()->create(['key' => 'leads', 'label' => 'Leads', 'table_name' => 'leads', 'base_type' => 'person', 'enabled' => true]);
    Field::query()->create(['module_id' => $leads->id, 'name' => 'full_name', 'type' => 'text', 'label_key' => 'LBL_FULL_NAME', 'storage' => 'column']);

    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);

    expect(Layout::query()->count())->toBe(0);
});

it('does not crash on a legacy file guarded by the sugarEntry direct-access check', function () {
    seedCompaniesModuleWithFields();
    writeLegacyViewDef('GA_Companies', 'listviewdefs.php', <<<'PHP'
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}
$module_name = 'GA_Companies';
$listViewDefs[$module_name] = array(
    'COMPANY_CONTACT_STATUS' => array('label' => 'LBL_STATUS', 'default' => true),
);
PHP);

    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);

    expect(Layout::query()->where('view', 'list')->first()->definition['content']['columns'])->toEqual([
        ['field' => 'company_contact_status', 'priority' => 1],
    ]);
});

it('produces nothing when LEGACY_PHP_ROOT is not configured, without erroring', function () {
    config(['etl.legacy_php_root' => null]);
    app()->forgetInstance(LegacyViewDefReader::class);
    seedCompaniesModuleWithFields();

    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);

    expect(Layout::query()->count())->toBe(0);
});

it('re-runs idempotently without duplicating a layout', function () {
    seedCompaniesModuleWithFields();
    writeLegacyViewDef('GA_Companies', 'listviewdefs.php', <<<'PHP'
$module_name = 'GA_Companies';
$listViewDefs[$module_name] = array(
    'COMPANY_CONTACT_STATUS' => array('label' => 'LBL_STATUS', 'default' => true),
);
PHP);

    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);
    $this->artisan('crm:import-studio-metadata', ['--only' => 'layouts'])->assertExitCode(0);

    expect(Layout::query()->where('view', 'list')->count())->toBe(1);
});

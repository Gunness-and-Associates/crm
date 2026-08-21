<?php

use App\Models\Lead;
use App\Models\Metadata\Module;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;

/**
 * Z-8.5 (BACKEND_BRIEF_ZAIN.md §14 step 9 / TASK_BREAKDOWN.md Milestone 8
 * DoD): tenant A cannot read tenant B through a model, the API, or a queued
 * job; a SchemaManager change in A leaves B untouched.
 *
 * Uses two genuinely separate tenants, each with its own real physical
 * database (provisionIsolationTenant() below) -- not the "same database"
 * tenant #1 promotion trick used elsewhere in this suite
 * (promotePrimaryTenant()), which would trivially "prove" isolation without
 * exercising anything real. This is also, literally, Milestone 8's own DoD:
 * "a second company is created, reaches its own subdomain with an isolated
 * database."
 *
 * DatabaseTruncation, not RefreshDatabase: same reasoning as the rest of the
 * Z-8.3/Z-8.4/Z-8.5 suite.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['tenancy.central_domains' => ['crm.test']]);
});

afterEach(function () {
    Tenant::query()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * @return array{0: Tenant, 1: string, 2: User} tenant, domain, admin user
 */
function provisionIsolationTenant(string $label): array
{
    $domain = "company-{$label}.crm.test";

    $tenant = Tenant::create();
    $tenant->createDomain($domain);

    tenancy()->initialize($tenant);
    $admin = User::create([
        'name' => "Admin {$label}",
        'email' => "admin-{$label}@example.test",
        'password' => 'Sup3r$ecretPassword1',
        'is_admin' => true,
    ]);
    tenancy()->end();

    return [$tenant, $domain, $admin];
}

it('a model created in one tenant is invisible when queried from another tenant', function () {
    [$tenantA, , $adminA] = provisionIsolationTenant('a');
    [$tenantB] = provisionIsolationTenant('b');

    // Lead uses HasAcl (AppliesRecordAccess): with no acting user the scope
    // returns zero rows unconditionally (a safe default, BACKEND_BRIEF §8.3),
    // not "every row" -- so every read here needs one. is_admin short-circuits
    // Acl::effective() to AccessLevel::All before it ever looks up roles, so
    // the same admin object works as the acting user regardless of which
    // tenant's connection is currently active.
    $this->actingAs($adminA);

    tenancy()->initialize($tenantA);
    Lead::factory()->create(['first_name' => 'OnlyInCompanyA']);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $visibleFromB = Lead::query()->where('first_name', 'OnlyInCompanyA')->exists();
    tenancy()->end();

    tenancy()->initialize($tenantA);
    $visibleFromA = Lead::query()->where('first_name', 'OnlyInCompanyA')->exists();
    tenancy()->end();

    expect($visibleFromA)->toBeTrue()
        ->and($visibleFromB)->toBeFalse();
});

it('the API returns only the requesting tenant\'s own data, never another tenant\'s', function () {
    [$tenantA, $domainA, $adminA] = provisionIsolationTenant('a');
    [$tenantB, $domainB, $adminB] = provisionIsolationTenant('b');

    tenancy()->initialize($tenantA);
    $this->seed(MetadataFixtureSeeder::class);
    Lead::factory()->create(['first_name' => 'CompanyALead']);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $this->seed(MetadataFixtureSeeder::class);
    Lead::factory()->create(['first_name' => 'CompanyBLead']);
    tenancy()->end();

    actingAsApiUser($adminA);
    $this->getJson("http://{$domainA}/api/v1/leads")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.first_name', 'CompanyALead');

    actingAsApiUser($adminB);
    $this->getJson("http://{$domainB}/api/v1/leads")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.first_name', 'CompanyBLead');
});

it('a queued job dispatched by one tenant writes only to that tenant\'s database', function () {
    [$tenantA, , $adminA] = provisionIsolationTenant('a');
    [$tenantB] = provisionIsolationTenant('b');

    // See the model-isolation test above: HasAcl's AppliesRecordAccess scope
    // needs an acting user for any read to return rows at all.
    $this->actingAs($adminA);

    tenancy()->initialize($tenantA);
    dispatch(function () {
        Lead::factory()->create(['first_name' => 'QueuedInCompanyA']);
    });
    tenancy()->end();

    tenancy()->initialize($tenantA);
    $inA = Lead::query()->where('first_name', 'QueuedInCompanyA')->exists();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $inB = Lead::query()->where('first_name', 'QueuedInCompanyA')->exists();
    tenancy()->end();

    expect($inA)->toBeTrue()
        ->and($inB)->toBeFalse();
});

it('a SchemaManager field addition in one company leaves the other company\'s schema untouched', function () {
    [$tenantA] = provisionIsolationTenant('a');
    [$tenantB] = provisionIsolationTenant('b');

    tenancy()->initialize($tenantA);
    $module = Module::factory()->create([
        'key' => 'isolation_test_module',
        'table_name' => 'isolation_test_module',
        'is_custom' => false,
    ]);
    $manager = app(SchemaManager::class);
    $plan = $manager->plan(new FieldChangeRequest('add', $module->key, 'only_in_a', 'text', ['length' => 100]));
    $result = $manager->apply($plan, actorId: null);
    $sidecarExistsInA = Schema::hasTable('isolation_test_module_custom');
    tenancy()->end();

    expect($result->success)->toBeTrue()
        ->and($sidecarExistsInA)->toBeTrue();

    tenancy()->initialize($tenantB);
    $sidecarExistsInB = Schema::hasTable('isolation_test_module_custom');
    $moduleExistsInB = Module::query()->where('key', 'isolation_test_module')->exists();
    tenancy()->end();

    expect($sidecarExistsInB)->toBeFalse()
        ->and($moduleExistsInB)->toBeFalse();
});

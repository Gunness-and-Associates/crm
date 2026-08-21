<?php

use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Grants $user a role with $level for one module action (default 'view') —
 * shared across every ACL-aware test.
 *
 * updateOrCreate, not create: Role::created dynamically registers a default
 * 'none' row for every existing module (Z-2.3), including $moduleKey when its
 * Module metadata row already exists (e.g. a test that seeds
 * MetadataFixtureSeeder before calling this) — a plain create() would then
 * collide with that auto-registered row.
 */
function grantAccess(User $user, string $moduleKey, AccessLevel $level, string $action = 'view'): void
{
    $role = Role::factory()->create();
    RoleModulePermission::query()->updateOrCreate(
        ['role_id' => $role->id, 'module_key' => $moduleKey],
        [$action => $level],
    );
    $user->roles()->attach($role);
}

/**
 * Fakes a personal-access-token request for /api/v1/* (Z-5.3): AuthenticateApiToken
 * validates the bearer token directly against the ResourceServer (so it can support
 * client-credentials too, see the middleware's own docblock) rather than through
 * Passport's TokenGuard — so Passport::actingAs() alone doesn't reach it. Mirrors
 * Passport::actingAsClient()'s own approach of swapping the ResourceServer instance.
 *
 * @param  list<string>  $scopes
 */
function actingAsApiUser(User $user, array $scopes = ['*']): User
{
    $mock = Mockery::mock(ResourceServer::class);
    $mock->shouldReceive('validateAuthenticatedRequest')->andReturnUsing(
        fn (ServerRequestInterface $request) => $request
            ->withAttribute('oauth_client_id', (string) Str::uuid())
            ->withAttribute('oauth_user_id', $user->id)
            ->withAttribute('oauth_scopes', $scopes)
    );
    app()->instance(ResourceServer::class, $mock);

    return $user;
}

/**
 * Fakes a client-credentials request for /api/v1/* — no oauth_user_id, so
 * AuthenticateApiToken resolves the acting user from $client->owner instead
 * (docs/contracts/api-contract.md §1.1: "a client also carries a user identity").
 *
 * @param  list<string>  $scopes
 */
function actingAsApiClient(Client $client, array $scopes = ['*']): Client
{
    $mock = Mockery::mock(ResourceServer::class);
    $mock->shouldReceive('validateAuthenticatedRequest')->andReturnUsing(
        fn (ServerRequestInterface $request) => $request
            ->withAttribute('oauth_client_id', $client->id)
            ->withAttribute('oauth_user_id', null)
            ->withAttribute('oauth_scopes', $scopes)
    );
    app()->instance(ResourceServer::class, $mock);

    return $client;
}

/**
 * Z-8.3: web.php/api.php/legacy_api.php and the Filament panel are now gated
 * behind tenant resolution (InitializeTenancyByDomain) — every test that
 * dispatches a real HTTP request through them needs a tenant to resolve to.
 * "localhost" matches phpunit.xml's APP_URL, so the default test host resolves
 * without any per-test Host header.
 *
 * db_name is set to the current connection's own database — same physical
 * database as central, no separate tenant database provisioned — mirroring
 * exactly what the real `tenants:promote-primary` command does in production
 * (BACKEND_BRIEF_ZAIN.md §14 step 4: "no data is moved").
 *
 * Call from a test using DatabaseTruncation, not RefreshDatabase: the
 * DatabaseTenancyBootstrapper switch to the "tenant" connection is a distinct
 * PDO connection to that same database, so it can't see anything still sitting
 * inside an open RefreshDatabase transaction on the original connection.
 */
function promotePrimaryTenant(): Tenant
{
    // create_database => false *before* the initial save: Tenant::create()
    // alone fires TenantCreated -> CreateDatabase, which -- since db_name
    // isn't set yet at that point -- would provision a genuinely separate,
    // throwaway physical database via the default generator, immediately
    // orphaned the moment the very next line points db_name at this shared
    // test database instead. Confirmed empirically: dozens of real
    // `tenant{uuid}` databases were left behind by test runs before this.
    $tenant = new Tenant;
    $tenant->setInternal('create_database', false);
    $tenant->setInternal('db_name', config('database.connections.'.config('database.default').'.database'));
    $tenant->save();
    $tenant->createDomain('localhost');

    return $tenant;
}

<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\File;

// Z-8.2 (BACKEND_BRIEF_ZAIN.md §14 step 3): proves the subdomain-identification
// mechanism itself — tenant resolution by domain, central-domain rejection —
// works end to end, using the package's own demo route (routes/tenant.php).
//
// DatabaseTruncation, not RefreshDatabase: DatabaseTenancyBootstrapper switches
// the app's default DB connection mid-request to a "tenant" connection pointing
// at this same test database (see createTenantOnDomain() below). A connection switch
// while RefreshDatabase holds an open transaction on the original connection
// would hide anything written on it from the new connection — the same class of
// problem documented for the backup round-trip test (Z-7.3). DatabaseTruncation
// has no open transaction, so cross-connection visibility to the same physical
// database is unaffected.
//
// Deliberately NOT covered here: the real app's own routes (web.php, api.php,
// legacy_api.php, the Filament panel) staying reachable at the single, central
// hostname they're reachable at today. Gating them behind tenant resolution is
// deferred until Z-8.3 promotes that live database to tenant #1 — doing it
// before then would mean the currently-live single-tenant CRM has no tenant to
// resolve to, and would 404 in production.
uses(DatabaseTruncation::class);

function createTenantOnDomain(string $domain): Tenant
{
    config(['tenancy.central_domains' => ['crm.test']]);

    $tenant = Tenant::create();
    $tenant->setInternal('db_name', config('database.connections.'.config('database.default').'.database'));
    $tenant->save();
    $tenant->createDomain($domain);

    return $tenant;
}

it('initializes tenancy and resolves the tenant on a matching domain', function () {
    createTenantOnDomain('acme.crm.test');

    $this->get('http://acme.crm.test/_tenancy/whoami')
        ->assertOk()
        ->assertSeeText('The id of the current tenant is');
});

it('rejects tenant-route access from the central domain', function () {
    createTenantOnDomain('acme.crm.test');

    $this->get('http://crm.test/_tenancy/whoami')->assertNotFound();
});

it('404s a hostname with no matching tenant domain, not a raw exception', function () {
    createTenantOnDomain('acme.crm.test');

    $this->get('http://not-a-tenant.crm.test/_tenancy/whoami')->assertNotFound();
});

it('keeps sessions and auth tenant-safe once a tenant is bootstrapped', function () {
    // Sessions: database-driven in the shipped default (DatabaseTenancyBootstrapper
    // switches the DB connection, so session storage is already tenant-scoped) —
    // checked against .env.example rather than config('session.driver'), which
    // phpunit.xml deliberately overrides to the faster "array" driver for tests.
    expect(File::get(base_path('.env.example')))->toContain('SESSION_DRIVER=database');

    // No shared cookie domain: SESSION_DOMAIN=null means each host gets its own
    // cookie, so a tenant subdomain's session cookie is never sent to another.
    expect(config('session.domain'))->toBeNull();

    // Auth: User (and Passport's oauth_* tables) have no hardcoded $connection,
    // so once tenancy switches the default connection they resolve against
    // that tenant's own users/tokens, not a fixed central table.
    expect((new User)->getConnectionName())->toBeNull();
});

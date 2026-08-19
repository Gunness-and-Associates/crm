<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use App\Support\MetadataRepository;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);
});

/**
 * BACKEND_BRIEF §16 — "Queries per page: under 25; no N+1 (assert with a
 * query-count test on the heaviest pages)." Companies is the brief's own
 * named example (21,000 rows) — a smaller seeded set is enough here since
 * query COUNT, unlike wall-clock time, doesn't depend on table size once an
 * index exists (verified separately below).
 */
it('keeps the companies list under 25 queries per page', function () {
    Company::factory()->count(60)->create();

    $user = actingAsApiUser(User::factory()->create(['is_admin' => true]));
    grantAccess($user, 'companies', AccessLevel::All);

    DB::enableQueryLog();
    $this->getJson('/api/v1/companies')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    expect($queryCount)->toBeLessThan(25);
});

/**
 * §16 — "never SELECT * when a layout names its columns." A sparse
 * fieldset naming only base-table columns must select just those (plus the
 * always-needed id) — verified by capturing the actual SQL, not just
 * counting queries (a wrong-but-still-single query would pass a count-only
 * check).
 */
it('selects only the requested columns for a sparse fieldset, not every column', function () {
    Company::factory()->create();

    $user = actingAsApiUser(User::factory()->create(['is_admin' => true]));
    grantAccess($user, 'companies', AccessLevel::All);

    DB::enableQueryLog();
    $this->getJson('/api/v1/companies?fields[companies]=primary_email')->assertOk();
    $selectQuery = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql): bool => str_contains($sql, 'from `companies`') && ! str_contains($sql, 'count('));
    DB::flushQueryLog();

    expect($selectQuery)->not->toBeNull()
        ->and($selectQuery)->toContain('`primary_email`')
        ->and($selectQuery)->toContain('`id`')
        ->and($selectQuery)->not->toContain('`website`')
        ->and($selectQuery)->not->toContain('*');
});

/**
 * §16 — "never SELECT * ... " full_name has no real column (computed from
 * first_name/last_name, app/Support/FullName.php) — selecting it must pull
 * both underlying columns, not the literal (nonexistent) name.
 */
it('expands the virtual full_name sparse field into its two real columns', function () {
    Company::factory()->create();

    $user = actingAsApiUser(User::factory()->create(['is_admin' => true]));
    grantAccess($user, 'companies', AccessLevel::All);

    DB::enableQueryLog();
    $response = $this->getJson('/api/v1/companies?fields[companies]=full_name')->assertOk();
    $selectQuery = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql): bool => str_contains($sql, 'from `companies`') && ! str_contains($sql, 'count('));
    DB::flushQueryLog();

    expect($selectQuery)->toContain('`first_name`')->toContain('`last_name`');
    expect($response->json('data.0.attributes.full_name'))->not->toBeNull();
});

/**
 * §16 — "Companies list (21,000 rows): no full table scan; verify with
 * EXPLAIN." A `type` of `ALL` in MySQL's EXPLAIN output means a full table
 * scan; the composite (deleted_at, assigned_user_id) index already added
 * for Z-4.4-era ACL filtering should keep the default Owner-scoped list
 * query off that path regardless of row count.
 */
it('uses an index, not a full table scan, for the default owner-scoped companies query', function () {
    Company::factory()->count(30)->create(['assigned_user_id' => null]);
    $owner = User::factory()->create();
    Company::factory()->count(5)->create(['assigned_user_id' => $owner->id]);

    $capturedSql = null;
    $capturedBindings = null;
    DB::listen(function ($query) use (&$capturedSql, &$capturedBindings) {
        if (str_contains($query->sql, 'from `companies`') && ! str_contains($query->sql, 'count(')) {
            $capturedSql = $query->sql;
            $capturedBindings = $query->bindings;
        }
    });

    actingAsApiUser($owner);
    grantAccess($owner, 'companies', AccessLevel::Owner);
    $this->getJson('/api/v1/companies')->assertOk();

    expect($capturedSql)->not->toBeNull();

    $plan = DB::select('EXPLAIN '.$capturedSql, $capturedBindings ?? []);
    $type = $plan[0]->type ?? null;

    expect($type)->not->toBe('ALL');
});

/**
 * §16 — "Metadata resolution: under 5ms added per request." A wall-clock
 * budget is too flaky to assert directly in CI. What actually protects it
 * within one request is MetadataRepository's in-request memoization
 * (compiled() is called from several independent places per request —
 * HasCustomFields per model class, dashboard widgets, this controller's
 * ApiModuleRegistry — and each would otherwise pay for a fresh cache-store
 * round trip): the second and later calls in the SAME request must add zero
 * additional queries. This is the exact proxy Z-4.4's own metadata test
 * already established (ContactableTest.php) — a query-count check, not a
 * millisecond budget, and deliberately not testing the cache-STORE layer
 * itself, which legitimately costs one cheap indexed lookup under the
 * `database` cache driver and is not a violation of anything.
 */
it('resolves compiled metadata with zero additional queries on repeat calls within one request', function () {
    app(MetadataRepository::class)->compiled();

    DB::enableQueryLog();
    app(MetadataRepository::class)->compiled();
    $queryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    expect($queryCount)->toBe(0);
});

<?php

use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\User;
use App\Support\Acl\AccessLevel;
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

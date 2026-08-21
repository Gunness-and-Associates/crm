<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 6). Provisions a genuinely new
 * tenant -- a real, separate physical database (unlike tenant #1's "same
 * database" promotion), created via the existing TenantCreated event
 * pipeline (Z-8.1) -- then verifies the roles and first administrator this
 * command adds on top of that.
 *
 * DatabaseTruncation, not RefreshDatabase: same reasoning as the rest of the
 * Z-8.3/Z-8.4 suite -- the command switches into the new tenant's own
 * connection to seed roles and create the admin.
 *
 * Cleanup: deletes the tenant at the end of every test, which fires
 * TenantDeleted -> the DeleteDatabase job (also wired in Z-8.1), dropping
 * the real database this test created. Without this, every run of this
 * file leaves a throwaway MySQL database behind.
 */
uses(DatabaseTruncation::class);

afterEach(function () {
    Tenant::query()->each(function (Tenant $tenant) {
        $tenant->delete();
    });
});

it('provisions a new tenant with its own database, domain, roles and first administrator', function () {
    $this->artisan('tenant:create', [
        'domain' => 'acme.crm.test',
        '--admin-name' => 'Ada Admin',
        '--admin-email' => 'ada@example.test',
        '--admin-password' => 'Sup3r$ecretPass1',
    ])->assertExitCode(0);

    $tenant = Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', 'acme.crm.test'))->first();
    expect($tenant)->not->toBeNull();

    tenancy()->initialize($tenant);

    try {
        expect(Role::query()->where('name', 'Administrator')->exists())->toBeTrue();

        $admin = User::query()->where('email', 'ada@example.test')->first();
        expect($admin)->not->toBeNull()
            ->and($admin->name)->toBe('Ada Admin')
            ->and($admin->is_admin)->toBeTrue()
            ->and($admin->roles->pluck('name'))->toContain('Administrator');
    } finally {
        tenancy()->end();
    }
});

it('generates and prints a password when none is given', function () {
    $this->artisan('tenant:create', [
        'domain' => 'beta.crm.test',
        '--admin-name' => 'Bea Admin',
        '--admin-email' => 'bea@example.test',
    ])
        ->expectsOutputToContain('Generated administrator password')
        ->assertExitCode(0);
});

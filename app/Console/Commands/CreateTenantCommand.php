<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsCatalogueSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 6). Provisions a brand-new tenant:
 * database creation and `database/migrations/tenant` run automatically via
 * the TenantCreated event pipeline already wired in
 * App\Providers\TenancyServiceProvider (Z-8.1) -- this command's own job is
 * everything after that: the domain, the starter roles, and the first
 * System Administrator so the tenant is actually usable, not just present.
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {domain : The hostname this tenant will be reachable on}
        {--admin-name= : First System Administrator'."'".'s name}
        {--admin-email= : First System Administrator'."'".'s email}
        {--admin-password= : First System Administrator'."'".'s password (generated and printed if omitted)}';

    protected $description = 'Provision a new tenant: database, migrations, starter roles, first admin (BACKEND_BRIEF Z-8.4)';

    public function handle(): int
    {
        $domain = $this->argument('domain');

        $adminName = $this->option('admin-name') ?: $this->ask('Administrator name');
        $adminEmail = $this->option('admin-email') ?: $this->ask('Administrator email');

        $generatedPassword = null;
        $adminPassword = $this->option('admin-password');
        if (! $adminPassword) {
            $adminPassword = $generatedPassword = Str::password(20);
        }

        if (! is_string($adminName) || $adminName === '' || ! is_string($adminEmail) || $adminEmail === '') {
            $this->error('Administrator name and email are required.');

            return self::FAILURE;
        }

        // Tenant::create() fires TenantCreated, which -- per Z-8.1's
        // TenancyServiceProvider -- runs CreateDatabase then MigrateDatabase
        // synchronously (shouldBeQueued(false)) before this returns.
        $tenant = Tenant::create();
        $tenant->createDomain($domain);

        tenancy()->initialize($tenant);

        try {
            $this->call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => SettingsCatalogueSeeder::class, '--force' => true]);

            $admin = User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $adminPassword,
                'is_admin' => true,
            ]);

            $adminRole = Role::query()->where('name', 'Administrator')->first();
            if ($adminRole) {
                $admin->roles()->attach($adminRole);
            }
        } finally {
            tenancy()->end();
        }

        $this->info("Tenant {$tenant->id} created, reachable at {$domain}.");

        if ($generatedPassword) {
            $this->warn("Generated administrator password (shown once): {$generatedPassword}");
        }

        return self::SUCCESS;
    }
}

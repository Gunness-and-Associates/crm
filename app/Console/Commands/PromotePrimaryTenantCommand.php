<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Z-8.3 (BACKEND_BRIEF_ZAIN.md §14 step 4) — one-time cutover step. Inserts a
 * tenant row whose database connection points at the app's own existing
 * database. No data is moved: db_name is set to the current default
 * connection's own database name, so the "tenant" connection stancl creates
 * on tenancy init is just another connection to that same physical database.
 *
 * Idempotent: running it again when tenant #1 already exists is a no-op.
 */
final class PromotePrimaryTenantCommand extends Command
{
    protected $signature = 'crm:promote-primary-tenant {domain : The hostname production traffic already arrives on}';

    protected $description = 'Register the existing live database as tenant #1 (BACKEND_BRIEF Z-8.3)';

    public function handle(): int
    {
        if (Tenant::query()->exists()) {
            $this->info('A tenant already exists -- nothing to promote.');

            return self::SUCCESS;
        }

        $domain = $this->argument('domain');

        $defaultConnection = config('database.default');
        $defaultConnection = is_string($defaultConnection) ? $defaultConnection : 'mysql';

        $tenant = new Tenant;
        $tenant->setInternal('db_name', config("database.connections.{$defaultConnection}.database"));
        $tenant->save();
        $tenant->createDomain($domain);

        $this->info("Tenant {$tenant->id} created, bound to the existing database, reachable at {$domain}.");

        return self::SUCCESS;
    }
}

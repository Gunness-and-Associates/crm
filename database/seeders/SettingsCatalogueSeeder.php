<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 6: "seed the settings catalogue").
 *
 * Every real Settings key in this app (SMTP credentials, ingest webhook
 * secrets, notification recipients -- see app/Support/Settings.php's own
 * consumers) is a per-deployment secret or address with no safe universal
 * default; seeding placeholder values for them would be actively wrong, not
 * merely unhelpful. There is currently no catalogue of non-secret defaults
 * this app expects a fresh tenant to start with either.
 *
 * This seeder is the named extension point CreateTenantCommand calls, kept
 * deliberately empty until such a catalogue is actually defined -- add
 * entries here (via app(Settings::class)->set(...)) once one is.
 */
class SettingsCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}

<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the starter roles from docs/reference/roles.php (BACKEND_BRIEF §8.5).
 *
 * NOTE: every planning doc states "29 roles", but the reference file itself
 * lists only 27 — reconcile against the source `acl_roles` table during the
 * Phase 6 ETL rather than guessing the missing two here.
 */
class RoleSeeder extends Seeder
{
    /** @var list<string> */
    private const ROLES = [
        'Administrator',
        'Appointment Setter - Regular',
        'Appointment Setter - Supervisor',
        'Assessment Score - Manager',
        'Assessment Score - Regular',
        'Assessment Score - Supervisor',
        'Associates Developer',
        'Client',
        'Client Development - Supervisor',
        'Client Development - User',
        'Email Templates',
        'In-Canada Module',
        'In-Canada Module - Supervisor',
        'Leads Only',
        'LMIA Inquiry - Regular User',
        'LMIA Lead - Regular',
        'LMIA Lead - Supervisor',
        'Only Email',
        'Recruiter',
        'Representatives Supervisor - Dildar',
        'Sales Representatives',
        'Sales Representatives - Dildar',
        'Sales Representatives - Jiya',
        'Study - Supervisor',
        'Study - New Regular',
        'USA - Regular',
        'USA - Supervisor',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                ['is_system' => $name === 'Administrator'],
            );
        }
    }
}

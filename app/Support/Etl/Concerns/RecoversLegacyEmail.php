<?php

namespace App\Support\Etl\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * BACKEND_BRIEF §13's email recovery query — every Contactable-derived legacy
 * module keeps its email(s) in the shared `email_addresses` table, joined via
 * `email_addr_bean_rel` and scoped to the module's own `bean_module` name.
 */
trait RecoversLegacyEmail
{
    private function recoverEmail(string $beanId, string $beanModule): ?string
    {
        $email = DB::connection('legacy')
            ->table('email_addr_bean_rel')
            ->join('email_addresses', 'email_addresses.id', '=', 'email_addr_bean_rel.email_address_id')
            ->where('email_addr_bean_rel.bean_id', $beanId)
            ->where('email_addr_bean_rel.bean_module', $beanModule)
            ->where('email_addr_bean_rel.deleted', 0)
            ->where('email_addresses.deleted', 0)
            ->orderByDesc('email_addr_bean_rel.primary_address')
            ->value('email_addresses.email_address');

        return is_string($email) && $email !== '' ? $email : null;
    }
}

<?php

namespace App\Policies;

final class ClientPolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'clients';
    }
}

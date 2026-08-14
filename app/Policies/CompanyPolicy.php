<?php

namespace App\Policies;

final class CompanyPolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'companies';
    }
}

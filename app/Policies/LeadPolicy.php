<?php

namespace App\Policies;

final class LeadPolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'leads';
    }
}

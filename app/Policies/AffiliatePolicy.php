<?php

namespace App\Policies;

final class AffiliatePolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'affiliates';
    }
}

<?php

namespace Tests\Fixtures;

use App\Policies\CrmPolicy;

class ContactableFixturePolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'contactable_fixtures';
    }
}

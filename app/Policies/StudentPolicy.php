<?php

namespace App\Policies;

final class StudentPolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'students';
    }
}

<?php

namespace App\Policies;

final class AssessmentPolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'assessments';
    }
}

<?php

namespace ExtendedPlan\Service;

use App\Service\PlanInterface;

class ExtendedPlanService implements PlanInterface
{
    public function getPlan(): string
    {
        return 'extended';
    }
}

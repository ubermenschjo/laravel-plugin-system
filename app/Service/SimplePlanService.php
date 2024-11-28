<?php

namespace App\Service;

class SimplePlanService implements PlanInterface
{
    public function getPlan(): string
    {
        return 'simple';
    }
}

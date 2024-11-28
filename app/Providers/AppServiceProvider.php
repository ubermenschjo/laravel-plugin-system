<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Service\PlanInterface;
use App\Service\SimplePlanService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerDefaultPlanBinding();
    }

    public function registerDefaultPlanBinding(): void
    {
        $this->app->forgetInstance(PlanInterface::class);
        $this->app->offsetUnset(PlanInterface::class);
        $this->app->bind(PlanInterface::class, SimplePlanService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
       
    }
}

<?php

namespace ExtendedPlan;

use App\Plugins\PluginInterface;
use App\Service\PlanInterface;
use ExtendedPlan\Service\ExtendedPlanService;
use App\Service\SimplePlanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use App\Plugins\PluginHelper;

class ExtendedPlan implements PluginInterface
{
    use PluginHelper;

    const NS = 'extendedPlan';

    public function __construct()
    {

    }

    public function register(): void
    {
        $this->registerService(PlanInterface::class, ExtendedPlanService::class);
        Log::debug('ExtendedPlan registered');
    }

    public function boot(): void
    {
        $this->registerRoute(self::NS, self::NS, __DIR__ . '/../routes/web.php');
        $this->registerView(self::NS, __DIR__ . '/../views');
        Log::debug('ExtendedPlan booted');
    }

    public function unregister(): void
    {
        $this->unregisterRouteAndView(self::NS);
        $this->registerService(PlanInterface::class, SimplePlanService::class); // 内部的にunregisterServiceも呼ばれる
        Log::debug('ExtendedPlan unregistered');
    }

    static public function view($view, $data = [])
    {
        return view(self::NS . '::' . $view, $data);
    }
}

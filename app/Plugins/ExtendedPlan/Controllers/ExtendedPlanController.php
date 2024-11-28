<?php

namespace App\Plugins\ExtendedPlan\Controllers;

use App\Http\Controllers\Controller;
use App\Service\PlanInterface;
class ExtendedPlanController extends Controller
{
    public function index()
    {
        return view('extendedPlan::index', ['plan' => app(PlanInterface::class)->getPlan()]);
    }
}

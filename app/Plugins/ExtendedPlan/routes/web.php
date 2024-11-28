<?php

use Illuminate\Support\Facades\Route;
use App\Plugins\ExtendedPlan\Controllers\ExtendedPlanController;

Route::get('/', [ExtendedPlanController::class, 'index'])->name('index');

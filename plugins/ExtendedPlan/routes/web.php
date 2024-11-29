<?php

use Illuminate\Support\Facades\Route;
use ExtendedPlan\Controllers\ExtendedPlanController;

Route::get('/', [ExtendedPlanController::class, 'index'])->name('index');

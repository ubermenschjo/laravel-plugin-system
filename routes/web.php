<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PluginController;
Route::get('/', function () {
    return view('welcome');
});

Route::get('/plugins', [PluginController::class, 'index'])->name('plugins.index');
Route::get('/plugins/{pluginName}/install', [PluginController::class, 'install'])->name('plugins.install');
Route::get('/plugins/{pluginName}/uninstall', [PluginController::class, 'uninstall'])->name('plugins.uninstall');

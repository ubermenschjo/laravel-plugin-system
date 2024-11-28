<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Plugins\PluginManager;


class PluginServiceProvider extends ServiceProvider
{
    public function register()
    {
        Event::listen('Illuminate\Database\Events\ConnectionEstablished', function () {
            $this->app->singleton(PluginManager::class, function ($app) {
                return new PluginManager();
            });
            $this->app->make(PluginManager::class)->registerPlugins();
        });
    }

    public function boot()
    {
        $this->app->make(PluginManager::class)->bootPlugins();
    }
} 
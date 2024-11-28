<?php

namespace App\Plugins;

interface PluginInterface
{
    public function register(): void;
    public function boot(): void;
    public function unregister(): void;
}

<?php

namespace App\Http\Controllers;

use App\Plugins\PluginManager;
use App\Service\PlanInterface;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class PluginController extends Controller
{
    protected $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    public function index()
    {
        $plugins = $this->pluginManager->getPlugins();
        $value = app(PlanInterface::class)->getPlan();
        $columns = DB::getSchemaBuilder()->getColumnListing('plans');
        $routes = Route::getRoutes();

        return view('plugins.index', compact('plugins', 'value', 'columns', 'routes'));
    }

    public function install($pluginName)
    {
        $this->pluginManager->installPlugin($pluginName);
        return redirect()->route('plugins.index')
        ->with('result', 'Plugin installed successfully.');
    }

    public function uninstall($pluginName)
    {
        $this->pluginManager->uninstallPlugin($pluginName);
        return redirect()->route('plugins.index')
        ->with('result', 'Plugin uninstalled successfully.');
    }
}
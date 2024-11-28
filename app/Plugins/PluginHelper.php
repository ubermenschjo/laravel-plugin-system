<?php

namespace App\Plugins;

use Illuminate\Support\Collection;
use App\Models\Plugin as PlugIn;
use Illuminate\Support\Facades\Route;

trait PluginHelper
{

    /**
     * アクティブなプラグインを取得
     * @return Collection
     */
    public function getActivePlugins(): Collection
    {
        return PlugIn::active()->get();
    }

    /**
     * 全てのプラグインを取得
     * @return Collection
     */
    public function getPlugins(): Collection
    {
        return PlugIn::all();
    }

    /**
     * サービスを登録
     * @param string $interface
     * @param string $class
     */
    public function registerService($interface, $class)
    {
        $this->unregisterService($interface);
        app()->bind($interface, $class);
    }

    /**
     * サービスを解除
     * @param string $interface
     */
    public function unregisterService($interface)
    {
        app()->forgetInstance($interface);
        app()->offsetUnset($interface);
    }

    /**
     * ルートを登録
     * @param string $prefix
     * @param string $namespace
     * @param string $path
     */
    public function registerRoute($prefix, $namespace, $path)
    {
        Route::middleware('web')
            ->prefix('/'. $prefix)
            ->name($namespace . '.')
            ->group($path);
    }

    /**
     * ビューを登録
     * @param string $namespace
     * @param string $path
     */
    public function registerView($namespace, $path)
    {
        view()->addNamespace($namespace, $path);
    }

    /**
     * ビューを解除
     * @param string $namespace
     */
    public function unregisterRouteAndView($prefix)
    {
        $router = app('router');
        $routes = $router->getRoutes();
        
        $newRouteCollection = new \Illuminate\Routing\RouteCollection();
        foreach ($routes->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), $prefix)) {
                $newRouteCollection->add($route);
            }
        }
        
        $router->setRoutes($newRouteCollection);

        $viewFactory = app('view');
        app()->forgetInstance('view');
        app()->instance('view', $viewFactory->getFinder()->flush());
    }

    /**
     * プラグイン名をクラス名に変換
     * @param string $pluginName
     * @return string
     */
    private function nameToClass($pluginName)
    {
        // if plugin name contains namespace, return it
        if (strpos($pluginName, '\\') !== false) {
            return $pluginName;
        }
        return 'App\\Plugins\\' . $pluginName . '\\' . $pluginName;
    }

    /**
     * クラス名をプラグイン名に変換
     * @param string $pluginClass
     * @return string
     */
    private function classToName($pluginClass)
    {
        $str = '';
        if (is_object($pluginClass)) {
            $str = get_class($pluginClass);
        } else if (is_string($pluginClass)) {
            $str = $pluginClass;
        }
        $arr = explode('\\', $str);
        return end($arr);
    }

}
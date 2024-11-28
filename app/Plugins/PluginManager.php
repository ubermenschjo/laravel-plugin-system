<?php
namespace App\Plugins;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

use App\Models\PlugIn;
class PluginManager
{
    use PluginHelper;
    
    protected $plugins = [];
    protected $autoActivate = false;
    protected $deleteOnUninstall = false;
    public function __construct()
    {
        $this->autoActivate = config('plugin.auto_activate', false);
        $this->deleteOnUninstall = config('plugin.delete_on_uninstall', false);
        $this->loadPlugins();
        if($this->autoActivate) {
            $this->activatePlugins();
            $this->registerPlugins();
            $this->bootPlugins();
        }
    }

    public function getActivePlugins(): Collection
    {
        return PlugIn::active()->get();
    }

    public function getPlugins(): Collection
    {
        return PlugIn::all();
    }

    private function loadPlugins($pluginName = null)
    {
        $pluginPath = app_path('Plugins');

        if (File::exists($pluginPath)) {
            $directories = File::directories($pluginPath);

            foreach ($directories as $directory) {
                if ($pluginName && basename($directory) !== $this->classToName($pluginName)) {
                    Log::debug('Skipping plugin: ' . basename($directory) . ' because it does not match ' . $pluginName);
                    continue;
                }
                
                $pluginFile = $directory . '/' . basename($directory) . '.php';
                $pluginClass = 'App\\Plugins\\' . basename($directory) . '\\' . basename($directory);

                if (!class_exists($pluginClass) && File::exists($pluginFile)) {
                    require_once $pluginFile;
                }

                if (class_exists($pluginClass)) {
                    $plugin = new $pluginClass();

                    if ($plugin instanceof PluginInterface && $this->isPluginActive($pluginClass)) {
                        $this->plugins[] = $plugin;
                        Log::debug('Loaded plugin: ' . $this->classToName($pluginClass));
                    }
                }
            }
        }
    }

    private function isPluginActive($pluginClass)
    {
        try {
            if (!PlugIn::where('class', $pluginClass)->exists()) {
                PlugIn::create([
                    'class' => $pluginClass,
                    'active' => $this->autoActivate,
                    'migrate_status' => 'pending',
                ]);
            }

            return PlugIn::where('class', $pluginClass)
                ->where('active', true)
                ->exists();
        } catch (\Exception $e) {
            Log::error('Plugin activation error: ' . $e->getMessage());
            return false;
        }
    }

    public function registerPlugins($pluginName = null)
    {
        foreach ($this->plugins as $plugin) {
            if ($pluginName && $this->classToName($plugin) !== $pluginName) {
                continue;
            }
            $plugin->register();
            Log::debug('Registered plugin: ' . $this->classToName($plugin));
        }
    }

    public function bootPlugins($pluginName = null)
    {
        foreach ($this->plugins as $plugin) {
            if ($pluginName && $this->classToName($plugin) !== $pluginName) {
                continue;
            }
            $plugin->boot();
            Log::debug('Booted plugin: ' . $this->classToName($plugin));
        }
    }

    public function installPlugin($pluginName)
    {
        PlugIn::updateOrCreate([
            'class' => $this->nameToClass($pluginName),
        ], [
            'active' => true,
        ]);
        $this->loadPlugins($pluginName);
        $this->activatePlugin($this->nameToClass($pluginName));
        $this->registerPlugins($pluginName);
        $this->bootPlugins($pluginName);
    }

    public function uninstallPlugin($pluginName)
    {
        $plugin = collect($this->plugins)->first(function ($plugin) use ($pluginName) {
            return $this->classToName($plugin) == $pluginName;
        });

        if ($plugin && method_exists($plugin, 'unregister')) {
            $plugin->unregister();
        }

        $this->rollbackMigrations($this->classToName($pluginName));
        $pluginPath = app_path('Plugins/' . $this->classToName($pluginName));

        if ($this->deleteOnUninstall && File::exists($pluginPath)) {
            File::deleteDirectory($pluginPath);
        }

        $this->plugins = collect($this->plugins)->reject(function ($plugin) use ($pluginName) {
            return $this->classToName($plugin) == $pluginName;
        })->toArray();

        PlugIn::where('class', $this->nameToClass($pluginName))
            ->update(['active' => false, 'migrate_status' => 'rollback']);
    }

    private function activatePlugins()
    {
        foreach ($this->plugins as $plugin) {
            $this->activatePlugin($plugin);
        }
    }

    private function activatePlugin($pluginClass)
    {
        $this->runMigrations($this->classToName($pluginClass));
        PlugIn::where('class', $pluginClass)
            ->update(['active' => true, 'migrate_status' => 'success']);
        Log::debug('Activated plugin: ' . $this->classToName($pluginClass));
    }

    private function runMigrations($pluginName = null)
    {
        $pluginPath = app_path('Plugins');

        if (File::exists($pluginPath)) {
            $directories = File::directories($pluginPath);

            foreach ($directories as $directory) {
                if ($pluginName && basename($directory) !== basename($pluginName)) {
                    Log::debug('Skipping migration for ' . basename($directory) . ' because it does not match ' . $pluginName);
                    continue;
                }

                $pluginClass = $this->nameToClass(basename($directory));
                if (!collect($this->plugins)->contains(function($plugin) use ($pluginClass) {
                    return get_class($plugin) === $pluginClass;
                })) {
                    Log::debug('Skipping migration for ' . basename($directory) . ' because it is not loaded');
                    continue;
                }

                $migrationPath = $directory . '/migrations';

                if (File::exists($migrationPath)) {
                    Artisan::call('migrate', [
                        '--path' => 'app/Plugins/' . $pluginName . '/migrations',
                    ]);
                    Log::debug('Migrated plugin: ' . $this->classToName($pluginClass));
                }
            }
        }
    }

    private function rollbackMigrations($pluginName)
    {
        $pluginPath = app_path('Plugins/' . $pluginName);

        if (File::exists($pluginPath)) {
            $migrationPath = $pluginPath . '/migrations';

            if (File::exists($migrationPath)) {
                Artisan::call('migrate:rollback', [
                    '--path' => 'app/Plugins/' . $pluginName . '/migrations',
                ]);
                Log::debug('Rolled back migrations for plugin: ' . $pluginName);
            }
        }
    }

    private function nameToClass($pluginName)
    {
        // if plugin name contains namespace, return it
        if (strpos($pluginName, '\\') !== false) {
            return $pluginName;
        }
        return 'App\\Plugins\\' . $pluginName . '\\' . $pluginName;
    }

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
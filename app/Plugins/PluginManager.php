<?php
namespace App\Plugins;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\DB;

use App\Models\PlugIn;
class PluginManager
{
    use PluginHelper;
    
    protected $plugins = [];
    protected $autoActivate = false;
    protected $deleteOnUninstall = false;
    protected $pluginConfigs = [];
    protected $loader;

    public function __construct()
    {
        $this->autoActivate = config('plugin.auto_activate', false);
        $this->deleteOnUninstall = config('plugin.delete_on_uninstall', false);
        $this->loader = require base_path('vendor/autoload.php');
        $this->loadPluginConfigs();
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

    private function loadPlugins($pluginNameParam = null)
    {
        foreach ($this->pluginConfigs as $pluginName => $config) {
            if ($pluginNameParam && $pluginName !== $pluginNameParam) {
                continue;
            }
            $pluginClass = $this->nameToClass($pluginName, $config);
            if (class_exists($pluginClass)) {
                $plugin = new $pluginClass();
                if ($plugin instanceof PluginInterface && $this->isPluginActive($pluginClass)) {
                    $this->plugins[] = $plugin;
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
        $pluginName = $this->classToName($pluginName);
        if (!isset($this->pluginConfigs[$pluginName])) {
            throw new \Exception("Plugin configuration not found: {$pluginName}");
        }

        $config = $this->pluginConfigs[$pluginName];
        $pluginClass = $this->nameToClass($pluginName, $config);
        
        PlugIn::updateOrCreate([
            'class' => $pluginClass,
        ], [
            'active' => true,
            'version' => $config['version'] ?? '1.0.0',
            'migrate_status' => 'pending'
        ]);

        $this->loadPlugins($pluginName);
        $this->activatePlugin($pluginClass);
        $this->registerPlugins($pluginName);
        $this->bootPlugins($pluginName);
    }

    public function uninstallPlugin($pluginName)
    {
        $pluginName = $this->classToName($pluginName);
        if (!isset($this->pluginConfigs[$pluginName])) {
            throw new \Exception("Plugin configuration not found: {$pluginName}");
        }

        $plugin = collect($this->plugins)->first(function ($plugin) use ($pluginName) {
            return $this->classToName($plugin) == $pluginName;
        });
        
        if ($plugin && method_exists($plugin, 'unregister')) {
            $plugin->unregister();
        }
        
        $this->rollbackMigrations($pluginName);

        $config = $this->pluginConfigs[$pluginName];
        $pluginPath = $config['basePath'];

        if ($this->deleteOnUninstall && File::exists($pluginPath)) {
            File::deleteDirectory($pluginPath);
        }

        $this->plugins = collect($this->plugins)->reject(function ($plugin) use ($pluginName) {
            return $this->classToName($plugin) == $pluginName;
        })->toArray();

        PlugIn::where('class', $this->nameToClass($pluginName, $config))
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

    /**
     * 모든 플러그인 디렉토리에서 설정 파일을 로드
     */
    protected function loadPluginConfigs()
    {
        $pluginPaths = config('plugin.paths', ['plugins']);
        
        foreach ($pluginPaths as $basePath) {
            if (!File::exists(base_path($basePath))) {
                continue;
            }

            $directories = File::directories(base_path($basePath));
            
            foreach ($directories as $directory) {
                $configFile = $directory . '/config.php';
                if (File::exists($configFile)) {
                    $pluginName = basename($directory);
                    $config = require $configFile;
                    
                    $this->pluginConfigs[$pluginName] = $config;
                    $this->registerPluginNamespace($config);
                }
            }
        }
    }

    /**
     * PSR-4 네임스페이스 등록
     */
    protected function registerPluginNamespace(array $config)
    {
        if (isset($config['psr4']) && is_array($config['psr4'])) {
            foreach ($config['psr4'] as $namespace => $path) {
                $this->loader->addPsr4($namespace, $path);
            }
        }
    }

    /**
     * 플러그인 재로드
     */
    private function reloadPlugin(string $pluginName, string $pluginClass, bool $isUpgrade, string $currentVersion)
    {
        // 기존 인스턴스 제거
        $this->plugins = collect($this->plugins)->reject(function ($plugin) use ($pluginClass) {
            return get_class($plugin) === $pluginClass;
        })->toArray();

        // 서비스 등록 해제
        if ($plugin = $this->findPluginInstance($pluginClass)) {
            $plugin->unregister();
        }

        // 컨피그 재로드
        $this->reloadPluginConfig($pluginName);

        // 새 버전으로 플러그인 로드
        $this->loadPlugins($pluginName);
        
        // 새 버전의 마이그레이션 실행
        if ($isUpgrade) {
            $this->runMigrations($pluginName);
        } else {
            $this->rollbackMigrations($pluginName, $currentVersion);
        }
        
        // 새 버전 활성화
        if ($plugin = $this->findPluginInstance($pluginClass)) {
            $plugin->register();
            $plugin->boot();
        }
        PlugIn::where('class', $pluginClass)->update([
            'active' => true,
            'migrate_status' => 'success'
        ]);
    }

    /**
     * 플러그인 설정 재로드
     */
    private function reloadPluginConfig(string $pluginName)
    {
        $pluginPaths = config('plugin.paths', ['plugins']);
        
        foreach ($pluginPaths as $basePath) {
            $configFile = base_path($basePath) . '/' . $pluginName . '/config.php';
            if (File::exists($configFile)) {
                $config = require $configFile;
                $this->pluginConfigs[$pluginName] = $config;
                $this->registerPluginNamespace($config);
                break;
            }
        }
    }

    /**
     * 플러그인 마이그레이션 실행
     */
    protected function runMigrations(string $pluginName)
    {
        $config = $this->pluginConfigs[$pluginName];
        $migrationPath = $this->getBasePathRelative($config['migrations']);
        $version = $config['version'] ?? '1.0.0';

        \Artisan::call('plugin:migrate', [
            'plugin' => $pluginName,
            '--path' => $migrationPath,
            '--force' => true,
            '--plugin-version' => $version
        ]);
    }

    protected function rollbackMigrations($pluginName, $currentVersion = null)
    {
        $config = $this->pluginConfigs[$pluginName];
        $migrationPath = $this->getBasePathRelative($config['migrations']);
        $version = $currentVersion ?? $config['version'] ?? '1.0.0';

        \Artisan::call('plugin:migrate:rollback', [
            'plugin' => $pluginName,
            '--path' => $migrationPath,
            '--force' => true,
            '--plugin-version' => $version
        ]);
    }

    /**
     * 플러그인 버전 변경
     */
    public function changeVersion(string $pluginName, string $targetVersion)
    {
        $pluginName = $this->classToName($pluginName);
        if (!isset($this->pluginConfigs[$pluginName])) {
            throw new \Exception("Plugin configuration not found: {$pluginName}");
        }

        $config = $this->pluginConfigs[$pluginName];
        $pluginClass = $this->nameToClass($pluginName, $config);
        
        // 현재 버전 확인
        $currentPlugin = PlugIn::where('class', $pluginClass)->first();
        if (!$currentPlugin) {
            throw new \Exception("Plugin not installed: {$pluginName}");
        }

        $currentVersion = $currentPlugin->version ?? '0.0.0';
        if ($currentVersion === $targetVersion) {
            throw new \Exception("Plugin is already at version {$targetVersion}");
        }

        $isUpgrade = version_compare($currentVersion, $targetVersion) < 0;
        
        try {
            DB::beginTransaction();
           
            // 플러그인 상태 업데이트
            PlugIn::where('class', $pluginClass)->update([
                'version' => $targetVersion,
                'migrate_status' => 'pending'
            ]);

            // 플러그인 재로드
            $this->reloadPlugin($pluginName, $pluginClass, $isUpgrade, $currentVersion);

            DB::commit();

            Log::info(
                "Plugin {$pluginName} " . 
                ($isUpgrade ? "upgraded" : "downgraded") . 
                " from {$currentVersion} to {$targetVersion}"
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Plugin version change failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 플러그인 인스턴스 찾기
     */
    private function findPluginInstance(string $pluginClass)
    {
        return collect($this->plugins)->first(function ($plugin) use ($pluginClass) {
            return get_class($plugin) === $pluginClass;
        });
    }
}

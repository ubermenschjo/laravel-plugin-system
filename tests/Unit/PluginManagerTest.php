<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Plugins\PluginManager;
use App\Models\Plugin;
use App\Plugins\PluginInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use App\Service\PlanInterface;
use App\Service\SimplePlanService;
use ExtendedPlan\Service\ExtendedPlanService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
class PluginManagerTest extends TestCase
{
    use RefreshDatabase;

    protected $pluginManager;
    protected $testPluginPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPluginPath = base_path('plugins/TestPlugin');

        Config::set('plugin.auto_activate', false);
        Config::set('plugin.delete_on_uninstall', false);
        Config::set('plugin.paths', ['plugins']);

        if (!File::exists($this->testPluginPath)) {
            File::makeDirectory($this->testPluginPath, 0755, true);
        }
        // config.php 파일 생성
        $configContent = <<<'EOT'
        <?php
        return [
            'namespace' => 'TestPlugin',
            'psr4' => [
                'TestPlugin\\' => __DIR__ . '/app'
            ],
            'basePath' => __DIR__,
            'migrations' => __DIR__ . '/migrations',
        ];
        EOT;
        File::put($this->testPluginPath . '/config.php', $configContent);
        if (!File::exists($this->testPluginPath . '/app')) {
            File::makeDirectory($this->testPluginPath . '/app', 0755, true);
        }
    
        $this->assertTrue(File::exists($this->testPluginPath), 'WARNING: TestPlugin directory was not created.');

        $this->pluginManager = new PluginManager();
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testPluginPath)) {
            File::deleteDirectory($this->testPluginPath);
        }
        $this->assertFalse(File::exists($this->testPluginPath), 'WARNING: TestPlugin directory was not deleted.');

        Mockery::close();
        parent::tearDown();
    }


    public function test_can_get_active_plugins()
    {
        Plugin::create([
            'class' => 'TestPlugin',
            'active' => true,
            'migrate_status' => 'success'
        ]);
        Plugin::create([
            'class' => 'InactivePlugin',
            'active' => false,
            'migrate_status' => 'success'
        ]);

        $activePlugins = $this->pluginManager->getActivePlugins();

        $this->assertEquals(1, $activePlugins->count());
        $this->assertEquals('TestPlugin', $activePlugins->first()->class);
    }


    public function test_can_get_all_plugins()
    {
        Plugin::create([
            'class' => 'Plugin1',
            'active' => true,
            'migrate_status' => 'success'
        ]);
        Plugin::create([
            'class' => 'Plugin2',
            'active' => false,
            'migrate_status' => 'pending'
        ]);

        $plugins = $this->pluginManager->getPlugins();
        $setupCount = 1;
        $this->assertEquals(2 + $setupCount, $plugins->count());
    }


    public function test_can_install_plugin_with_migration()
    {
        $migrationPath = base_path('plugins/TestPlugin/migrations');
        if (!File::exists($migrationPath)) {
            File::makeDirectory($migrationPath, 0755, true);
        }
        $this->assertTrue(File::exists($migrationPath), 'WARNING: TestPlugin/migrations directory was not created.');

        $migrationContent = <<<'EOT'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('test_plugin_table', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('test_plugin_table');
    }
};
EOT;

        $result = File::put($migrationPath . '/2024_11_27_000000_create_test_plugin_table.php', $migrationContent);
        $this->assertNotFalse($result, 'WARNING: TestPlugin/migrations/2024_11_27_000000_create_test_plugin_table.php was not created.');

        Artisan::shouldReceive('call')
            ->once()
            ->with('plugin:migrate', [
                'plugin' => 'TestPlugin',
                '--path' => 'plugins/TestPlugin/migrations',
                '--force' => true,
            ]);

        // make class
        $pluginClass = 'TestPlugin\\TestPlugin';
        $pluginContent = <<<'EOT'
<?php

namespace TestPlugin;

use App\Plugins\PluginInterface;

class TestPlugin implements PluginInterface
{
    public function register():void {}
    public function boot():void {}
    public function unregister(): void {}
}
EOT;
        $result = File::put($this->testPluginPath . '/app/TestPlugin.php', $pluginContent);
        $this->assertNotFalse($result, 'WARNING: TestPlugin/TestPlugin.php was not created.');

        $this->pluginManager->installPlugin('TestPlugin');

        $this->assertDatabaseHas('plugins', [
            'class' => $pluginClass,
            'active' => true,
            'migrate_status' => 'success'
        ]);
    }


    public function test_can_uninstall_plugin_with_migration()
    {

        $migrationPath = $this->testPluginPath . '/migrations';
        if (!File::exists($migrationPath)) {
            File::makeDirectory($migrationPath, 0755, true);
        }

        $migrationContent = <<<'EOT'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('test_plugin_table', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('test_plugin_table');
    }
};
EOT;

        File::put($migrationPath . '/2024_11_27_000000_create_test_plugin_table.php', $migrationContent);

        $pluginClass = 'TestPlugin\\TestPlugin';
        Plugin::create([
            'class' => $pluginClass,
            'active' => true,
            'migrate_status' => 'success'
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('plugin:migrate:rollback', [
                'plugin' => 'TestPlugin',
                '--path' => 'plugins/TestPlugin/migrations',
                '--force' => true,
            ]);

        $this->pluginManager->uninstallPlugin($pluginClass);

        $this->assertDatabaseHas('plugins', [
            'class' => $pluginClass,
            'active' => false,
            'migrate_status' => 'rollback'
        ]);
    }

    public function test_can_register_binding_when_install()
    {


        $this->assertInstanceOf(
            SimplePlanService::class,
            app()->make(PlanInterface::class)
        );


        $this->pluginManager->installPlugin('ExtendedPlan');


        $this->assertInstanceOf(
            ExtendedPlanService::class,
            app()->make(PlanInterface::class)
        );

        $this->assertTrue(
            $this->pluginManager->getActivePlugins()
                ->where('class', 'ExtendedPlan\\ExtendedPlan')
                ->first()
                ->active
        );

        $this->assertEquals(
            'extended',
            app()->make(PlanInterface::class)->getPlan()
        );
    }

    public function test_can_unregister_binding_when_uninstall()
    {
        $this->pluginManager->installPlugin('ExtendedPlan');
        $this->assertInstanceOf(
            ExtendedPlanService::class,
            app()->make(PlanInterface::class)
        );

        $this->pluginManager->uninstallPlugin('ExtendedPlan');

        $this->assertInstanceOf(
            SimplePlanService::class,
            app()->make(PlanInterface::class)
        );

        $this->assertTrue(
            $this->pluginManager->getActivePlugins()
                ->isEmpty()
        );

        $this->assertEquals(
            'simple',
            app()->make(PlanInterface::class)->getPlan()
        );
    }

    public function test_can_register_plugin_routes()
    {
        $this->pluginManager->installPlugin('ExtendedPlan');

        $routes = Route::getRoutes();

        $testRoute = collect($routes->getRoutes())->first(function ($route) {
            return $route->uri() === 'extendedPlan';
        });

        $this->assertNotNull($testRoute);
        $this->assertEquals(
            'ExtendedPlan\Controllers\ExtendedPlanController@index',
            $testRoute->getAction()['controller']
        );
    }

    public function test_can_register_plugin_views()
    {
        $this->pluginManager->installPlugin('ExtendedPlan');

        $this->assertTrue(View::exists('extendedPlan::index'));
    }

}
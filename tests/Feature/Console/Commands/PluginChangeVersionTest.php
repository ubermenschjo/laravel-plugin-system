<?php

namespace Tests\Feature\Console\Commands;

use Tests\TestCase;
use App\Models\PlugIn;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Mockery;

class PluginChangeVersionTest extends TestCase
{
    use RefreshDatabase;
    protected $testPluginPath;
    protected $migrationPath;
    protected $originalPluginManager;

    protected function setUp(): void
    {
        parent::setUp();
        // 원본 PluginManager 인스턴스 저장
        $this->originalPluginManager = app(PluginManager::class);

        // 테스트용 플러그인 디렉토리 생성
        $this->testPluginPath = base_path('plugins/TestPlugin');
        $this->migrationPath = $this->testPluginPath . '/migrations';
        
        if (!File::exists($this->testPluginPath)) {
            File::makeDirectory($this->testPluginPath, 0755, true);
        }
        if (!File::exists($this->migrationPath)) {
            File::makeDirectory($this->migrationPath, 0755, true);
        }

        // 테스트용 마이그레이션 파일 생성
        $this->createTestMigration('2024_01_01_000000_create_test_table.php');

        // 플러그인 레코드 생성
        PlugIn::updateOrCreate([
            'class' => 'TestPlugin\\TestPlugin',
        ], [
            'active' => true,
            'version' => '1.0.0',
            'migrate_status' => 'success'
        ]);

        // plugin_migrations 테이블에 1.0.0 버전의 마이그레이션 레코드 생성
        DB::table('plugin_migrations')->insert([
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_test_table.php',
            'version' => '1.0.0',
            'batch' => 1
        ]);
    }

    protected function tearDown(): void
    {
        // 테스트용 플러그인 디렉토리 삭제
        if (File::exists($this->testPluginPath)) {
            File::deleteDirectory($this->testPluginPath);
        }
        
        DB::table('plugin_migrations')->delete();
        
        // PluginManager를 원래 상태로 복원
        $this->app->instance(PluginManager::class, $this->originalPluginManager);
        
        parent::tearDown();
    }

    protected function mockPluginManager($pluginName = 'TestPlugin', $version = '1.0.0')
    {
        $mock = Mockery::mock(PluginManager::class)->makePartial();
        
        $reflection = new \ReflectionClass($mock);
        $property = $reflection->getProperty('pluginConfigs');
        $property->setAccessible(true);
        $property->setValue($mock, [
            $pluginName => [
                'namespace' => $pluginName,
                'version' => $version,
                'psr4' => [
                    $pluginName . '\\' => $this->testPluginPath . '/app'
                ],
                'basePath' => $this->testPluginPath,
                'migrations' => $this->migrationPath,
            ]
        ]);

        $this->app->instance(PluginManager::class, $mock);
        
        return $mock;
    }

    protected function createTestMigration($fileName)
    {
        $migrationContent = <<<'EOT'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('test_table', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('test_table');
            }
        };
        EOT;
        File::put($this->migrationPath . '/' . $fileName, $migrationContent);
    }

    public function test_it_can_change_plugin_version()
    {
        // PluginManager의 pluginConfigs 모킹 업데이트
        $this->mockPluginManager('TestPlugin', '2.0.0');

        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0',
            '--force' => true
        ])->assertSuccessful()
          ->expectsOutput("Successfully changed TestPlugin's version to 2.0.0");

        $this->assertDatabaseHas('plugins', [
            'class' => 'TestPlugin\\TestPlugin',
            'version' => '2.0.0'
        ]);
    }

    public function test_it_fails_when_plugin_not_found()
    {
        $this->artisan('plugin:version', [
            'plugin' => 'NonExistentPlugin',
            'version' => '2.0.0',
            '--force' => true
        ])->assertFailed()
          ->expectsOutput('Plugin configuration not found: NonExistentPlugin');
    }

    public function test_it_fails_when_version_is_same()
    {
        $this->mockPluginManager('TestPlugin', '1.0.0');
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '1.0.0',
            '--force' => true
        ])->assertFailed()
          ->expectsOutput('Plugin is already at version 1.0.0');
    }

    public function test_it_not_updates_migration_version_when_empty_migration()
    {
        $this->mockPluginManager('TestPlugin', '2.0.0');
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0',
            '--force' => true
        ])->assertSuccessful();

        $this->assertDatabaseMissing('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0'
        ]);
    }

    public function test_it_requires_confirmation_without_force_option()
    {
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0'
        ])->expectsConfirmation(
            "Are you sure you want to change TestPlugin's version to 2.0.0?",
            'no'
        )->assertSuccessful();

        $this->assertDatabaseHas('plugins', [
            'class' => 'TestPlugin\\TestPlugin',
            'version' => '1.0.0'  // 버전이 변경되지 않아야 함
        ]);
    }

    public function test_it_can_downgrade_plugin_version_with_empty_migration()
    {
        $this->mockPluginManager('TestPlugin', '2.0.0');
        // 먼저 2.0.0 버전으로 업그레이드
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0',
            '--force' => true
        ])->assertSuccessful();

        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'version' => '1.0.0' // 마이그레이션 파일이 없기 때문에 기존 마이그레이션 정보밖에 없음
        ]);
        $this->assertDatabaseHas('plugins', [
            'class' => 'TestPlugin\\TestPlugin',
            'version' => '2.0.0'
        ]);

        // 1.0.0 버전으로 다운그레이드
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '1.0.0',
            '--force' => true
        ])->assertSuccessful()
          ->expectsOutput("Successfully changed TestPlugin's version to 1.0.0");

        // 플러그인 버전이 1.0.0으로 변경되었는지 확인
        $this->assertDatabaseHas('plugins', [
            'class' => 'TestPlugin\\TestPlugin',
            'version' => '1.0.0'
        ]);

        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'version' => '1.0.0'
        ]);
    }

    public function test_it_rolls_back_migrations_to_previous_version_when_downgrading()
    {
        // 2.0.0 버전용 마이그레이션 파일 생성
        $migrationContent = <<<'EOT'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('test_table_v2', function (Blueprint $table) {
                    $table->id();
                    $table->string('new_column');
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('test_table_v2');
            }
        };
        EOT;
        File::put($this->migrationPath . '/2024_01_01_000001_create_test_table_v2.php', $migrationContent);


        // mock PluginManager : 업그레이드 할 때, 해당 버전의 코드를 덮어씌워서 배포하기 때문에 업그레이드 버전을 모킹해야 함
        $this->mockPluginManager('TestPlugin', '2.0.0');

        // 2.0.0 버전으로 업그레이드
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0',
            '--force' => true
        ])->assertSuccessful();

        // plugins 테이블에 2.0.0 버전의 플러그인이 있는지 확인
        $this->assertDatabaseHas('plugins', [
            'class' => 'TestPlugin\\TestPlugin',
            'version' => '2.0.0',
            'migrate_status' => 'success',
            'active' => true
        ]);

        // test_table_v2가 생성되었는지 확인
        $this->assertTrue(Schema::hasTable('test_table_v2'));

        // 2.0.0 버전의 마이그레이션이 성공적으로 실행되었는지 확인
        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0',
            'migration' => '2024_01_01_000001_create_test_table_v2.php'
        ]);
        // 1.0.0 버전의 마이그레이션에 영향이 없는지 확인
        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'version' => '1.0.0',
            'migration' => '2024_01_01_000000_create_test_table.php'
        ]);

        // 1.0.0 버전으로 다운그레이드
        $this->artisan('plugin:version', [
            'plugin' => 'TestPlugin',
            'version' => '1.0.0',
            '--force' => true
        ])->assertSuccessful();

        // 2.0.0 버전의 마이그레이션이 롤백되었는지 확인
        $this->assertDatabaseMissing('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'version' => '2.0.0',
            'migration' => '2024_01_01_000001_create_test_table_v2.php'
        ]);

        // test_table_v2가 삭제되었는지 확인
        $this->assertFalse(Schema::hasTable('test_table_v2'));
    }
} 
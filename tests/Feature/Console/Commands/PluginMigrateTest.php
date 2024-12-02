<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PluginMigrateTest extends TestCase
{
    use RefreshDatabase;

    protected $testPluginPath;
    protected $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 테스트용 플러그인 디렉토리 생성
        $this->testPluginPath = base_path('plugins/TestPlugin');
        $this->migrationPath = $this->testPluginPath . '/migrations';
        
        if (!File::exists($this->testPluginPath)) {
            File::makeDirectory($this->testPluginPath, 0755, true);
        }
        
        if (!File::exists($this->migrationPath)) {
            File::makeDirectory($this->migrationPath, 0755, true);
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
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testPluginPath)) {
            File::deleteDirectory($this->testPluginPath);
        }
        parent::tearDown();
    }

    public function test_can_run_plugin_migration(): void
    {
        // 테스트용 마이그레이션 파일 생성
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
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('test_table');
            }
        };
        EOT;

        File::put($this->migrationPath . '/2024_01_01_000000_create_test_table.php', $migrationContent);

        // 마이그레이션 실행
        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();

        // 테이블이 생성되었는지 확인
        $this->assertTrue(Schema::hasTable('test_table'));

        // plugin_migrations 테이블에 기록되었는지 확인
        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_test_table.php'
        ]);
    }

    public function test_can_run_migration_with_specific_path(): void
    {
        // 테스트용 마이그레이션 파일 생성
        $migrationContent = <<<'EOT'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('path_test_table', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('path_test_table');
            }
        };
        EOT;

        File::put($this->migrationPath . '/2024_01_01_000000_create_path_test_table.php', $migrationContent);

        // 특정 경로로 마이그레이션 실행
        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--path' => 'plugins/TestPlugin/migrations',
            '--force' => true
        ])->assertSuccessful();

        // 테이블이 생성되었는지 확인
        $this->assertTrue(Schema::hasTable('path_test_table'));
    }

    public function test_skips_already_run_migrations(): void
    {
        // 이미 실행된 마이그레이션으로 기록
        DB::table('plugin_migrations')->insert([
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_duplicate_test_table.php',
            'batch' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 동일한 마이그레이션 파일 생성
        $migrationContent = <<<'EOT'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('duplicate_test_table', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('duplicate_test_table');
            }
        };
        EOT;

        File::put($this->migrationPath . '/2024_01_01_000000_create_duplicate_test_table.php', $migrationContent);

        // 마이그레이션 실행
        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();

        // 중복 실행되지 않았는지 확인 (batch가 여전히 1인지)
        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_duplicate_test_table.php',
            'batch' => 1
        ]);
    }
}

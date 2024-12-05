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
        
        $this->preparePluginDirectory();
        $this->createPluginConfig();
    }

    protected function tearDown(): void
    {
        // 테스트용 플러그인 디렉토리 정리
        $this->cleanupPluginDirectory();
        
        parent::tearDown();
    }

    /**
     * 플러그인 디렉토리 준비
     */
    protected function preparePluginDirectory(): void
    {
        if (!File::exists($this->testPluginPath)) {
            File::makeDirectory($this->testPluginPath, 0755, true);
        }
        
        if (!File::exists($this->migrationPath)) {
            File::makeDirectory($this->migrationPath, 0755, true);
        }
    }

    /**
     * 플러그인 설정 파일 생성
     */
    protected function createPluginConfig(): void
    {
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

    /**
     * 플러그인 디렉토리 정리
     */
    protected function cleanupPluginDirectory(): void
    {
        if (File::exists($this->testPluginPath)) {
            File::deleteDirectory($this->testPluginPath);
        }
    }

    /**
     * 테스트용 마이그레이션 파일 생성
     */
    protected function createMigrationFile(string $tableName, array $columns = []): void
    {
        $columnDefinitions = empty($columns) ? [
            '$table->id();',
            '$table->string(\'name\');',
            '$table->timestamps();'
        ] : $columns;

        $columnsString = implode("\n", $columnDefinitions);

        $migrationContent = <<<EOT
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('$tableName', function (Blueprint \$table) {
                    $columnsString
                });
            }

            public function down()
            {
                Schema::dropIfExists('$tableName');
            }
        };
        EOT;

        File::put($this->migrationPath . "/2024_01_01_000000_create_{$tableName}.php", $migrationContent);
    }

    public function test_can_run_plugin_migration(): void
    {
        $this->createMigrationFile('test_table');

        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('test_table'));
        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_test_table.php'
        ]);
    }

    public function test_can_run_migration_with_specific_path(): void
    {
        $this->createMigrationFile('path_test_table');

        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--path' => 'plugins/TestPlugin/migrations',
            '--force' => true
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('path_test_table'));
    }

    public function test_skips_already_run_migrations(): void
    {
        DB::table('plugin_migrations')->insert([
            'plugin' => 'TestPlugin',
            'version' => '1.0.0',
            'migration' => '2024_01_01_000000_create_duplicate_test_table.php',
            'batch' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->createMigrationFile('duplicate_test_table');

        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();

        $this->assertDatabaseHas('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_duplicate_test_table.php',
            'batch' => 1
        ]);
    }
}

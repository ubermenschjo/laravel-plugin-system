<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PluginMigrateRollbackTest extends TestCase
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

    public function test_can_rollback_plugin_migration(): void
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
                Schema::create('rollback_test_table', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('rollback_test_table');
            }
        };
        EOT;

        File::put($this->migrationPath . '/2024_01_01_000000_create_rollback_test_table.php', $migrationContent);

        // 먼저 마이그레이션 실행
        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ]);

        // 롤백 실행
        $this->artisan('plugin:migrate:rollback', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();

        // 테이블이 삭제되었는지 확인
        $this->assertFalse(Schema::hasTable('rollback_test_table'));

        // plugin_migrations 테이블에서 기록이 삭제되었는지 확인
        $this->assertDatabaseMissing('plugin_migrations', [
            'plugin' => 'TestPlugin',
            'migration' => '2024_01_01_000000_create_rollback_test_table.php'
        ]);
    }

    public function test_can_rollback_with_specific_path(): void
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
                Schema::create('path_rollback_test_table', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('path_rollback_test_table');
            }
        };
        EOT;

        File::put($this->migrationPath . '/2024_01_01_000000_create_path_rollback_test_table.php', $migrationContent);

        // 먼저 마이그레이션 실행
        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--path' => 'plugins/TestPlugin/migrations',
            '--force' => true
        ]);

        // 특정 경로로 롤백 실행
        $this->artisan('plugin:migrate:rollback', [
            'plugin' => 'TestPlugin',
            '--path' => 'plugins/TestPlugin/migrations',
            '--force' => true
        ])->assertSuccessful();

        // 테이블이 삭제되었는지 확인
        $this->assertFalse(Schema::hasTable('path_rollback_test_table'));
    }

    public function test_handles_missing_migration_files(): void
    {
        // plugin_migrations 테이블에 존재하지 않는 마이그레이션 기록 추가
        DB::table('plugin_migrations')->insert([
            'plugin' => 'TestPlugin',
            'migration' => 'non_existent_migration.php',
            'batch' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 롤백 실행 - 존재하지 않는 파일은 무시되어야 함
        $this->artisan('plugin:migrate:rollback', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();
    }

    public function test_rollback_multiple_migrations_in_batch(): void
    {
        // 첫 번째 마이그레이션 파일
        $migrationContent1 = <<<'EOT'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('batch_test_table1', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('batch_test_table1');
            }
        };
        EOT;

        // 두 번째 마이그레이션 파일
        $migrationContent2 = <<<'EOT'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('batch_test_table2', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                });
            }

            public function down()
            {
                Schema::dropIfExists('batch_test_table2');
            }
        };
        EOT;

        File::put($this->migrationPath . '/2024_01_01_000000_create_batch_test_table1.php', $migrationContent1);
        File::put($this->migrationPath . '/2024_01_01_000001_create_batch_test_table2.php', $migrationContent2);

        // 마이그레이션 실행
        $this->artisan('plugin:migrate', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ]);

        // 롤백 실행
        $this->artisan('plugin:migrate:rollback', [
            'plugin' => 'TestPlugin',
            '--force' => true
        ])->assertSuccessful();

        // 두 테이블 모두 삭제되었는지 확인
        $this->assertFalse(Schema::hasTable('batch_test_table1'));
        $this->assertFalse(Schema::hasTable('batch_test_table2'));
    }
}

<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginMigrateStatusTest extends TestCase
{
    use RefreshDatabase;


    protected function setUp(): void
    {
        parent::setUp();
        
        if (!File::exists(base_path('plugins/test-plugin/migrations'))) {
            File::makeDirectory(base_path('plugins/test-plugin/migrations'), 0755, true);
            File::put(base_path('plugins/test-plugin/migrations/2024_01_01_000000_create_test_table.php'), 'dummy content');
            File::put(base_path('plugins/test-plugin/migrations/2024_01_01_000001_add_column_to_test.php'), 'dummy content');
        }
        if (!File::exists(base_path('custom/path/migrations'))) {
            File::makeDirectory(base_path('custom/path/migrations'), 0755, true);
            File::put(base_path('custom/path/migrations/2024_01_01_000000_test.php'), 'dummy content');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/test-plugin'));
        File::deleteDirectory(base_path('custom'));
        parent::tearDown();
    }


    public function test_it_shows_error_when_migration_path_does_not_exist()
    {
        $this->artisan('plugin:migrate:status', [
            'plugin' => 'not-exist-plugin'
        ])->assertExitCode(1)
          ->expectsOutput('Migration path does not exist: plugins/not-exist-plugin/migrations');
    }

    public function test_it_shows_pending_and_completed_migrations()
    {
        // 하나의 마이그레이션은 실행된 것으로 설정
        DB::table('plugin_migrations')->insert([
            'plugin' => 'test-plugin',
            'version' => '1.0.0',
            'migration' => '2024_01_01_000000_create_test_table.php',
            'batch' => 1
        ]);

        $this->artisan('plugin:migrate:status', [
            'plugin' => 'test-plugin'
        ])->assertExitCode(0)
          ->expectsTable(['Migration name', 'Batch / Status'], [
            ['2024_01_01_000000_create_test_table.php', 'Ran (Batch 1)'],
            ['2024_01_01_000001_add_column_to_test.php', 'Pending']
          ]);
    }

    public function test_it_handles_custom_migration_path()
    {
        $this->artisan('plugin:migrate:status', [
            'plugin' => 'test-plugin',
            '--path' => 'custom/path/migrations'
        ])->assertExitCode(0)
          ->expectsTable(['Migration name', 'Batch / Status'], [
            ['2024_01_01_000000_test.php', 'Pending']
          ]);
    }
} 
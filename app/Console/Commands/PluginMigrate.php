<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PluginMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:migrate {plugin?} {--force} {--path=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run plugin migrations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pluginName = $this->argument('plugin');
        $force = $this->option('force');
        $path = $this->option('path');

        if (!$force && !$this->confirm('Are you sure you want to run plugin migrations?')) {
            return;
        }

        if ($path) {
            // path 옵션이 있는 경우 직접 마이그레이션 실행
            $this->runMigrationsFromPath($pluginName, $path);
            return;
        }

        // 기존 로직 (config.php 기반)
        $pluginPaths = config('plugin.paths', ['app/Plugins']);
        foreach ($pluginPaths as $basePath) {
            if (!File::exists(base_path($basePath))) {
                continue;
            }

            $directories = $pluginName 
                ? [base_path($basePath . '/' . $pluginName)]
                : File::directories(base_path($basePath));

            foreach ($directories as $directory) {
                $configFile = $directory . '/config.php';
                if (!File::exists($configFile)) {
                    continue;
                }

                $config = require $configFile;
                $currentPluginName = basename($directory);

                $this->runMigrations($currentPluginName, $config);
            }
        }
    }

    protected function runMigrations(string $pluginName, array $config)
    {
        $migrationPath = $config['migrations'] ?? null;
        if (!$migrationPath || !File::exists($migrationPath)) {
            return;
        }

        $files = File::glob($migrationPath . '/*.php');
        $batch = $this->getNextBatchNumber($pluginName);

        foreach ($files as $file) {
            $migration = require $file;
            $fileName = basename($file);

            if ($this->hasMigration($pluginName, $fileName)) {
                continue;
            }

            $migration->up();

            DB::table('plugin_migrations')->insert([
                'plugin' => $pluginName,
                'migration' => $fileName,
                'batch' => $batch,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info("Migrated: {$fileName}");
        }
    }

    protected function runMigrationsFromPath(string $pluginName, string $path)
    {
        $fullPath = base_path($path);
        if (!File::exists($fullPath)) {
            $this->error("Migration path does not exist: {$path}");
            return;
        }

        $files = File::glob($fullPath . '/*.php');
        $batch = $this->getNextBatchNumber($pluginName);

        foreach ($files as $file) {
            $migration = require $file;
            $fileName = basename($file);

            if ($this->hasMigration($pluginName, $fileName)) {
                continue;
            }

            $migration->up();

            DB::table('plugin_migrations')->insert([
                'plugin' => $pluginName,
                'migration' => $fileName,
                'batch' => $batch,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info("Migrated: {$fileName}");
        }
    }

    private function getNextBatchNumber(string $pluginName): int
    {
        return DB::table('plugin_migrations')
            ->where('plugin', $pluginName)
            ->max('batch') + 1 ?? 1;
    }

    private function hasMigration(string $pluginName, string $fileName): bool
    {
        return DB::table('plugin_migrations')
            ->where('plugin', $pluginName)
            ->where('migration', $fileName)
            ->exists();
    }
}

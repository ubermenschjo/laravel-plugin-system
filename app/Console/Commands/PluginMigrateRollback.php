<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PluginMigrateRollback extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:migrate:rollback {plugin?} {--force} {--path=} {--plugin-version=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback plugin migrations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pluginName = $this->argument('plugin');
        $force = $this->option('force');
        $path = $this->option('path');

        if (!$force && !$this->confirm('Are you sure you want to rollback plugin migrations?')) {
            return;
        }

        if ($path) {
            // path 옵션이 있는 경우 직접 롤백 실행
            $this->rollbackMigrationsFromPath($pluginName, $path, $this->option('plugin-version'));
            return;
        }

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

                $this->rollbackMigrations($currentPluginName, $config, $this->option('plugin-version'));
            }
        }
    }

    protected function rollbackMigrations(string $pluginName, array $config, $version = null)
    {
        $migrationPath = $config['migrations'] ?? null;
        if (!$migrationPath || !File::exists($migrationPath)) {
            return;
        }

        $batch = $this->getLastBatchNumber($pluginName);
        $version = $version ?? $config['version'];
        $migrations = DB::table('plugin_migrations')
            ->where('plugin', $pluginName)
            ->when($version, function ($query, $version) {
                return $query->where('version', $version);
            })
            ->where('batch', $batch)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($migrations as $migration) {
            $file = $migrationPath . '/' . $migration->migration;
            if (File::exists($file)) {
                $instance = require $file;
                $instance->down();

                DB::table('plugin_migrations')
                    ->where('id', $migration->id)
                    ->delete();

                $this->info("Rolled back: {$migration->migration}");
            }
        }
    }

    protected function rollbackMigrationsFromPath($plugin, $path, $version = null)
    {
        $migrations = DB::table('plugin_migrations')
            ->where('plugin', $plugin)
            ->when($version, function ($query, $version) {
                return $query->where('version', $version);
            })
            ->orderBy('batch', 'desc')
            ->get();

        foreach ($migrations as $migration) {
            $file = base_path($path) . '/' . $migration->migration;
            if (File::exists($file)) {
                $instance = include $file;
                $instance->down();

                DB::table('plugin_migrations')
                    ->where('id', $migration->id)
                    ->delete();
            }
        }
    }

    private function getLastBatchNumber(string $pluginName): int
    {
        return DB::table('plugin_migrations')
            ->where('plugin', $pluginName)
            ->max('batch') ?? 0;
    }
}
